// The shared api() helper has no abort support, so chat requests (which can be stopped) use this thin variant of it.
export function streamId(random = globalThis.crypto) {
    if (random.randomUUID) return random.randomUUID();
    // getRandomValues also works on local-network HTTP development origins.
    const bytes = random.getRandomValues(new Uint8Array(16)); bytes[6] = (bytes[6] & 15) | 64; bytes[8] = (bytes[8] & 63) | 128;
    const hex = Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

export async function postJson(path, method, body, signal, onEvent) {
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    let res;
    try {
        res = await fetch(`/api${path}`, {
            method, signal, credentials: 'same-origin',
            headers: { Accept: onEvent ? 'text/event-stream' : 'application/json', 'Content-Type': 'application/json', ...(onEvent ? { 'X-Chat-Stream-Id': streamId() } : {}), ...(m ? { 'X-XSRF-TOKEN': decodeURIComponent(m[1]) } : {}) },
            body: JSON.stringify(body),
        });
    } catch (e) {
        if (e.name === 'AbortError') throw e;
        const err = new Error('The connection was interrupted. Check your conversations before sending again. Your draft is kept.'); err.code = 'network'; throw err;
    }
    if (res.ok && res.headers.get('Content-Type')?.includes('text/event-stream')) {
        const reader = res.body.getReader(); const decoder = new TextDecoder(); let buffer = ''; let result = null; let failed = null; let conversation = null;
        try {
            while (true) {
                const { value, done } = await reader.read(); buffer += decoder.decode(value || new Uint8Array(), { stream: !done });
                if (buffer.length > 2 * 1024 * 1024) throw new Error('The streamed answer exceeded its limit. Reopen this conversation to check the saved response.');
                let boundary;
                while ((boundary = buffer.indexOf('\n\n')) !== -1) {
                    const event = buffer.slice(0, boundary); buffer = buffer.slice(boundary + 2);
                    const kind = event.split('\n').find(l => l.startsWith('event:'))?.slice(6).trim();
                    const lines = event.split('\n').filter(l => l.startsWith('data:')); if (!lines.length) continue;
                    const data = JSON.parse(lines.map(l => l.slice(5).trimStart()).join('\n'));
                    if (kind === 'failure') { failed = Object.assign(new Error(data.message), { code: data.code, details: data.details || {} }); }
                    else if (kind === 'complete') result = { data };
                    else { if (kind === 'started') conversation = data.conversation; onEvent?.(kind, data); }
                }
                if (done) break;
            }
        } catch (e) { if (e.name === 'AbortError') throw e; throw Object.assign(new Error('The response connection was interrupted. Reopen this conversation before retrying.'), { code: 'stream_interrupted', details: conversation ? { conversation } : {} }); }
        finally { reader.releaseLock(); }
        if (failed) { if (failed.code === 'auth_changed') window.dispatchEvent(new CustomEvent('api:session-lost', { detail: { code: failed.code, message: failed.message } })); throw failed; }
        if (!result) throw new Error('The connection ended before the response finished. Reopen this conversation before retrying.');
        return result;
    }
    let json = null;
    try { json = await res.json(); } catch { /* not JSON */ }
    if (!res.ok) {
        const e = json?.error;
        const err = new Error(e?.message ?? 'Something went wrong.'); err.code = e?.code ?? 'http_error'; err.details = e?.details ?? {}; err.status = res.status;
        if (res.status === 401) window.dispatchEvent(new CustomEvent('api:session-lost', { detail: { code: err.code, message: err.message } }));
        throw err;
    }
    return { data: json?.data };
}

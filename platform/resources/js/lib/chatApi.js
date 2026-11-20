// The shared api() helper has no abort support, so chat requests (which can be stopped) use this thin variant of it.
export async function postJson(path, method, body, signal) {
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    let res;
    try {
        res = await fetch(`/api${path}`, {
            method, signal, credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(m ? { 'X-XSRF-TOKEN': decodeURIComponent(m[1]) } : {}) },
            body: JSON.stringify(body),
        });
    } catch (e) {
        if (e.name === 'AbortError') throw e;
        const err = new Error('Could not reach the server. Check your connection and try again.'); err.code = 'network'; throw err;
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

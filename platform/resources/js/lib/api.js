// Thin client for the Laravel API envelope: {data} on success, {error:{code,message,details}} on failure.
export class ApiError extends Error {
    constructor(code, message, details = {}, status = 0) {
        super(message);
        this.code = code;
        this.details = details;
        this.status = status;
    }
}

// Laravel puts the CSRF token in the XSRF-TOKEN cookie; echo it back in a header on writes.
function xsrfToken() {
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : null;
}

// Error codes that mean "this browser session is no longer valid" (anything but a bad login attempt).
const SESSION_LOST = new Set(['unauthenticated', 'session_replaced', 'session_terminated', 'account_deactivated']);

export async function api(path, { method = 'GET', body } = {}) {
    const headers = { Accept: 'application/json' };
    if (body) headers['Content-Type'] = 'application/json';
    if (method !== 'GET') {
        const t = xsrfToken();
        if (t) headers['X-XSRF-TOKEN'] = t;
    }

    let res;
    try {
        res = await fetch(`/api${path}`, { method, headers, credentials: 'same-origin', body: body ? JSON.stringify(body) : undefined });
    } catch {
        throw new ApiError('network', 'Could not reach the server. Check your connection and try again.');
    }
    let json = null;
    try { json = await res.json(); } catch { /* non-JSON response */ }
    if (!res.ok) {
        const e = json?.error;
        const err = new ApiError(e?.code ?? 'http_error', e?.message ?? 'Something went wrong.', e?.details ?? {}, res.status);
        if (res.status === 401 && SESSION_LOST.has(err.code)) {
            window.dispatchEvent(new CustomEvent('api:session-lost', { detail: { code: err.code, message: err.message } }));
        }
        throw err;
    }
    return { data: json?.data, meta: json?.meta };
}

// Convenience: most callers only want the data.
export const get = (path) => api(path).then((r) => r.data);
export const send = (method, path, body) => api(path, { method, body: body ?? {} }).then((r) => r.data);

// First validation message for a (dotted) field name, if any.
export const fieldError = (err, name) => err?.details?.[name]?.[0];

// Multipart upload (files). The browser sets the multipart boundary itself, so no Content-Type header here.
export async function upload(path, formData) {
    const t = xsrfToken();
    let res;
    try {
        res = await fetch(`/api${path}`, { method: 'POST', headers: { Accept: 'application/json', ...(t ? { 'X-XSRF-TOKEN': t } : {}) }, credentials: 'same-origin', body: formData });
    } catch {
        throw new ApiError('network', 'Could not reach the server. Check your connection and try again.');
    }
    let json = null;
    try { json = await res.json(); } catch { /* non-JSON response */ }
    if (!res.ok) {
        const e = json?.error;
        const err = new ApiError(e?.code ?? 'http_error', e?.message ?? 'Something went wrong.', e?.details ?? {}, res.status);
        if (res.status === 401 && SESSION_LOST.has(err.code)) window.dispatchEvent(new CustomEvent('api:session-lost', { detail: { code: err.code, message: err.message } }));
        throw err;
    }
    return json?.data;
}

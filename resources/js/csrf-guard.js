/**
 * CSRF staleness guard — kills the "Your session expired for security" loop.
 *
 * Mobile browsers and CDNs sometimes serve a CACHED login (or any) page whose
 * hidden <input name="_token"> points at a session that has since been swept
 * from the DB. The next POST then fails Laravel's CSRF check → 419 → the
 * graceful "session expired" bounce lands the user back on the same cached
 * page → it happens again and again.
 *
 * Fix: fetch a LIVE token (one that matches the CURRENT session cookie) from
 * /csrf-token and write it into every <input name="_token"> plus the
 * <meta name="csrf-token"> tag, so a cached form still submits a valid token.
 * We do this on first paint and on back/forward (bfcache) restore.
 *
 * Deliberately does NOT intercept form submits — that keeps the reCAPTCHA v3
 * submit handler (auth-recaptcha.js) and any AJAX form handlers untouched.
 */

const origFetch = window.fetch.bind(window);

function applyToken(tok) {
    if (!tok) return;
    document.querySelectorAll('input[name="_token"]').forEach((i) => { i.value = tok; });
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (meta) meta.setAttribute('content', tok);
}

function csrfPresent() {
    return document.querySelector('meta[name="csrf-token"], input[name="_token"]');
}

async function refreshToken() {
    if (!csrfPresent()) return '';
    try {
        const url = (typeof window.appUrl === 'function') ? window.appUrl('/csrf-token') : '/csrf-token';
        const res = await origFetch(url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            cache: 'no-store',
        });
        if (!res.ok) return '';
        const data = await res.json();
        const tok = (data && data.token) || '';
        applyToken(tok);
        return tok;
    } catch (e) {
        return '';
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', refreshToken);
} else {
    refreshToken();
}

window.addEventListener('pageshow', (e) => { if (e.persisted) refreshToken(); });
setInterval(refreshToken, 10 * 60 * 1000);

window.fetch = async function (input, init) {
    const res = await origFetch(input, init);
    if (res.status !== 419 || (init && init.__csrfRetried)) return res;
    const before = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const tok = await refreshToken();
    if (!tok || tok === before) return res;
    const headers = new Headers((init && init.headers) || (input instanceof Request ? input.headers : undefined));
    headers.set('X-CSRF-TOKEN', tok);
    const next = Object.assign({}, init, { headers: headers, __csrfRetried: true });
    return origFetch(input, next);
};

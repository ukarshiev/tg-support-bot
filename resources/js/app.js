import './bootstrap';

// ── PWA: register the admin service worker ───────────────────────────────────
// Only on /admin/* pages and only in a secure context (HTTPS or localhost) —
// a plain-HTTP dev host can't register a service worker, so we skip it there.
if (
    'serviceWorker' in navigator &&
    window.isSecureContext &&
    window.location.pathname.startsWith('/admin')
) {
    window.addEventListener('load', () => {
        navigator.serviceWorker
            .register('/admin/sw.js', { scope: '/admin/' })
            .catch(() => {
                /* registration is best-effort; the app works without it */
            });
    });
}

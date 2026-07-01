import './bootstrap';

const adminThemeStorageKey = 'tg-support-bot-admin-theme';

const getPreferredAdminTheme = () => {
    const savedTheme = window.localStorage?.getItem(adminThemeStorageKey);

    if (savedTheme === 'dark' || savedTheme === 'light') {
        return savedTheme;
    }

    return window.matchMedia?.('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
};

const applyAdminTheme = (theme) => {
    document.documentElement.dataset.theme = theme;
    document.documentElement.style.colorScheme = theme;

    const metaThemeColor = document.querySelector('meta[name="theme-color"]');
    if (metaThemeColor) {
        metaThemeColor.setAttribute('content', theme === 'dark' ? '#0F172A' : '#1B1F2E');
    }
};

applyAdminTheme(getPreferredAdminTheme());

window.adminTheme = {
    get: getPreferredAdminTheme,
    set(theme) {
        const nextTheme = theme === 'dark' ? 'dark' : 'light';
        window.localStorage?.setItem(adminThemeStorageKey, nextTheme);
        applyAdminTheme(nextTheme);
        window.dispatchEvent(new CustomEvent('admin-theme-changed', { detail: { theme: nextTheme } }));
    },
    toggle() {
        this.set(getPreferredAdminTheme() === 'dark' ? 'light' : 'dark');
    },
};

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

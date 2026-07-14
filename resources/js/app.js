import './bootstrap';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

const runtimeReverb = window.__REVERB__ ?? {};
const reverbKey = runtimeReverb.key ?? import.meta.env.VITE_REVERB_APP_KEY;
if (reverbKey) {
    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: reverbKey,
        wsHost: import.meta.env.VITE_REVERB_HOST ?? window.location.hostname,
        wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? window.location.port ?? 80),
        wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? window.location.port ?? 443),
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? window.location.protocol.replace(':', '')) === 'https',
        enabledTransports: ['ws', 'wss'],
        authEndpoint: '/broadcasting/auth',
    });

    window.Echo.private('support')
        .listen('.support.message.committed', (event) => {
            window.dispatchEvent(new CustomEvent('support-message-committed', { detail: event }));
            window.Livewire?.dispatch('support-message-committed', {
                messageId: event.message_id,
                conversationId: event.conversation_id,
                traceId: event.trace_id,
            });
        });
}

const adminThemeStorageKey = 'tg-support-bot-admin-theme';
const adminThemeCookieName = 'tg_support_admin_theme';
const adminThemeValues = ['dark', 'light'];

const getAdminThemeFromCookie = () => {
    const cookies = `; ${document.cookie}`;
    const parts = cookies.split(`; ${adminThemeCookieName}=`);
    const theme = parts.length === 2 ? parts.pop().split(';').shift() : null;

    return adminThemeValues.includes(theme) ? theme : null;
};

const setAdminThemeCookie = (theme) => {
    document.cookie = `${adminThemeCookieName}=${theme}; path=/; max-age=31536000; SameSite=Lax`;
};

const getPreferredAdminTheme = () => {
    const savedTheme = window.localStorage?.getItem(adminThemeStorageKey);

    if (adminThemeValues.includes(savedTheme)) {
        return savedTheme;
    }

    const cookieTheme = getAdminThemeFromCookie();
    if (cookieTheme) {
        return cookieTheme;
    }

    return window.matchMedia?.('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
};

const applyAdminTheme = (theme) => {
    document.documentElement.dataset.theme = theme;
    document.documentElement.style.colorScheme = theme;
    setAdminThemeCookie(theme);

    const metaThemeColor = document.querySelector('meta[name="theme-color"]');
    if (metaThemeColor) {
        metaThemeColor.setAttribute('content', theme === 'dark' ? '#0F172A' : '#1B1F2E');
    }
};

applyAdminTheme(getPreferredAdminTheme());

window.adminTheme = {
    get: getPreferredAdminTheme,
    set(theme) {
        const nextTheme = adminThemeValues.includes(theme) ? theme : 'light';
        window.localStorage?.setItem(adminThemeStorageKey, nextTheme);
        applyAdminTheme(nextTheme);
        window.dispatchEvent(new CustomEvent('admin-theme-changed', { detail: { theme: nextTheme } }));
    },
    toggle() {
        this.set(getPreferredAdminTheme() === 'dark' ? 'light' : 'dark');
    },
};

window.addEventListener('storage', (event) => {
    if (event.key !== adminThemeStorageKey || !adminThemeValues.includes(event.newValue)) {
        return;
    }

    applyAdminTheme(event.newValue);
    window.dispatchEvent(new CustomEvent('admin-theme-changed', { detail: { theme: event.newValue } }));
});

const darkenLivewireErrorFrame = (dialog) => {
    if (getPreferredAdminTheme() !== 'dark') {
        return;
    }

    const iframe = dialog?.querySelector?.('iframe');
    const frameDocument = iframe?.contentDocument;

    if (!frameDocument?.head || !frameDocument?.body) {
        return;
    }

    frameDocument.documentElement.dataset.theme = 'dark';
    frameDocument.documentElement.style.colorScheme = 'dark';
    frameDocument.body.style.background = '#17161a';
    frameDocument.body.style.color = '#e5e7eb';

    if (frameDocument.getElementById('tg-support-livewire-error-theme')) {
        return;
    }

    const style = frameDocument.createElement('style');
    style.id = 'tg-support-livewire-error-theme';
    style.textContent = `
        html, body {
            background: #17161a !important;
            color: #e5e7eb !important;
        }
        body > * {
            background-color: transparent !important;
        }
    `;
    frameDocument.head.appendChild(style);
};

const watchLivewireErrorDialog = () => {
    const apply = (dialog) => {
        const iframe = dialog?.querySelector?.('iframe');

        darkenLivewireErrorFrame(dialog);
        iframe?.addEventListener('load', () => darkenLivewireErrorFrame(dialog), { once: false });
    };

    document.querySelectorAll('dialog#livewire-error').forEach(apply);

    new MutationObserver((mutations) => {
        for (const mutation of mutations) {
            mutation.addedNodes.forEach((node) => {
                if (!(node instanceof HTMLElement)) {
                    return;
                }

                if (node.matches('dialog#livewire-error')) {
                    apply(node);
                    return;
                }

                node.querySelectorAll?.('dialog#livewire-error').forEach(apply);
            });
        }
    }).observe(document.documentElement, { childList: true, subtree: true });
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', watchLivewireErrorDialog, { once: true });
} else {
    watchLivewireErrorDialog();
}

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

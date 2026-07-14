<script>
    (() => {
        const storageKey = 'tg-support-bot-admin-theme';
        const cookieName = 'tg_support_admin_theme';
        const themes = ['light', 'dark'];

        const readStoredTheme = () => {
            try {
                const theme = window.localStorage.getItem(storageKey);
                return themes.includes(theme) ? theme : null;
            } catch {
                return null;
            }
        };

        const readCookieTheme = () => {
            const cookies = `; ${document.cookie}`;
            const parts = cookies.split(`; ${cookieName}=`);
            const theme = parts.length === 2 ? parts.pop().split(';').shift() : null;

            return themes.includes(theme) ? theme : null;
        };

        const readSystemTheme = () => (
            window.matchMedia?.('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
        );

        const writeCookieTheme = (theme) => {
            document.cookie = `${cookieName}=${theme}; path=/; max-age=31536000; SameSite=Lax`;
        };

        const theme = readStoredTheme() || readCookieTheme() || readSystemTheme();

        document.documentElement.dataset.theme = theme;
        document.documentElement.style.colorScheme = theme;
        writeCookieTheme(theme);
    })();
</script>

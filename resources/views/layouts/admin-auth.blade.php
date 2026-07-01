<!DOCTYPE html>
<html lang="ru" class="h-full">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />

    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}" sizes="any" />

    <title>Вход — Admin</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles

    <style>[x-cloak]{display:none !important;}</style>
</head>
    <script>
        (() => {
            const key = 'tg-support-bot-admin-theme';
            const saved = localStorage.getItem(key);
            const theme = saved || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            document.documentElement.dataset.theme = theme;
            document.documentElement.style.colorScheme = theme;
        })();
    </script>

<body class="h-full bg-bg-secondary font-sans text-text-primary antialiased">

    {{ $slot }}

    @livewireScripts
</body>
</html>

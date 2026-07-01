<!DOCTYPE html>
<html lang="ru" class="h-full">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />

    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}" sizes="any" />

    {{-- PWA: installable admin app --}}
    <link rel="manifest" href="{{ route('admin.pwa.manifest') }}" />
    <meta name="theme-color" content="#1B1F2E" />
    <link rel="apple-touch-icon" href="{{ asset('icons/apple-touch-icon.png') }}" />
    <meta name="apple-mobile-web-app-capable" content="yes" />
    <meta name="mobile-web-app-capable" content="yes" />
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" />
    <meta name="apple-mobile-web-app-title" content="TG Support" />

    <title>Чаты — Admin</title>

    <script>
        (() => {
            const key = 'tg-support-bot-admin-theme';
            const saved = localStorage.getItem(key);
            const theme = saved || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            document.documentElement.dataset.theme = theme;
            document.documentElement.style.colorScheme = theme;
        })();
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles

    <style>[x-cloak]{display:none !important;}</style>
</head>
<body class="h-full overflow-hidden bg-bg-primary font-sans text-text-primary antialiased">

    {{ $slot }}

    {{-- Transient toasts — listens for the `admin-toast` Livewire event
         (replaces the former Filament notifications). --}}
    <div
        x-data="{ toasts: [] }"
        x-on:admin-toast.window="
            const id = Date.now() + Math.random();
            toasts.push({ id, message: $event.detail.message, type: $event.detail.type || 'success' });
            setTimeout(() => { toasts = toasts.filter(t => t.id !== id); }, 3500);
        "
        class="fixed top-4 right-4 z-[100] flex flex-col gap-2"
        style="pointer-events:none;"
        aria-live="polite"
    >
        <template x-for="toast in toasts" :key="toast.id">
            <div
                x-transition.opacity.duration.200ms
                class="flex items-center gap-2 rounded-lg px-4 py-3 text-sm font-medium text-white shadow-lg"
                :class="toast.type === 'danger' ? 'bg-red-600' : 'bg-emerald-600'"
                style="min-width:220px;"
            >
                <span x-text="toast.message"></span>
            </div>
        </template>
    </div>

    @include('partials.notification-sounds')

    @livewireScripts
</body>
</html>

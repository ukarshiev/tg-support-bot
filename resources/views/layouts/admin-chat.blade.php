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

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    @filamentStyles

    <style>[x-cloak]{display:none !important;}</style>
</head>
<body class="h-full overflow-hidden bg-bg-primary font-sans text-text-primary antialiased">

    {{ $slot }}

    @livewireScripts
    @filamentScripts
</body>
</html>

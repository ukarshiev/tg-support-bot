<!DOCTYPE html>
@php
    $adminCookieHeader = (string) request()->headers->get('cookie', '');
    preg_match('/(?:^|;\s*)tg_support_admin_theme=(dark|light)(?:;|$)/', $adminCookieHeader, $adminThemeMatch);
    $adminInitialTheme = $adminThemeMatch[1] ?? null;
@endphp
<html lang="ru" class="h-full" @if($adminInitialTheme) data-theme="{{ $adminInitialTheme }}" style="color-scheme: {{ $adminInitialTheme }}" @endif>
<head>
    <meta charset="UTF-8" />
    @include('partials.admin-theme-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />

    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}" sizes="any" />

    <title>Вход — Admin</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles

    <style>[x-cloak]{display:none !important;}</style>
</head>
<body class="h-full bg-bg-secondary font-sans text-text-primary antialiased">

    {{ $slot }}

    @livewireScripts
</body>
</html>

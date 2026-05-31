<!DOCTYPE html>
<html lang="ru" class="h-full">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />

    <title>Чаты — Admin</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    @filamentStyles
</head>
<body class="h-full overflow-hidden bg-bg-primary font-sans text-text-primary antialiased">

    {{ $slot }}

    @livewireScripts
    @filamentScripts
</body>
</html>

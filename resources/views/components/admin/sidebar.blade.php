{{--
    Admin sidebar layout component.

    Props:
      $title       — sidebar heading text (default "Настройки")
      $backUrl     — URL for the back chevron (default route('admin.chats') or '/admin')
      $version     — version string shown in footer (default "v8.0.0")
      $docsUrl     — URL for "Документация" footer link (default "https://docs.tg-support-bot.ru/")
--}}
@props([
    'title'   => 'Настройки',
    'backUrl' => null,
    'version' => 'v8.0.0',
    'docsUrl' => 'https://docs.tg-support-bot.ru/',
])

@php
    $resolvedBackUrl = $backUrl ?? (Route::has('admin.chats')
        ? route('admin.chats')
        : '/admin');
@endphp

<aside class="flex h-full w-full shrink-0 flex-col bg-sidebar text-text-sidebar lg:w-70">
    {{-- Header --}}
    <div class="flex items-center gap-3 px-4 py-5">
        <a href="{{ $resolvedBackUrl }}"
           class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-text-sidebar-secondary transition hover:bg-sidebar-hover hover:text-text-sidebar"
           aria-label="Назад"
           title="Вернуться назад">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
            </svg>
        </a>
        <span class="text-base font-semibold tracking-tight">{{ $title }}</span>
    </div>

    {{-- Nav items --}}
    <nav class="flex-1 overflow-y-auto px-3 pb-4" aria-label="Настройки навигация">
        {{ $slot }}
    </nav>

    {{-- Logout --}}
    <div class="px-3 pb-2">
        <form method="POST" action="{{ route('admin.logout') }}">
            @csrf
            <button
                type="submit"
                class="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-text-sidebar-secondary transition hover:bg-sidebar-hover hover:text-text-sidebar"
                title="Выйти из аккаунта"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="h-[18px] w-[18px] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
                    <polyline points="16 17 21 12 16 7" />
                    <line x1="21" x2="9" y1="12" y2="12" />
                </svg>
                Выйти
            </button>
        </form>
    </div>

    {{-- Footer --}}
    <div
        class="flex items-center gap-2 border-t border-border-sidebar px-4 py-4 text-xs text-text-sidebar-secondary"
        x-data="{ theme: window.adminTheme?.get?.() || 'light' }"
        x-on:admin-theme-changed.window="theme = $event.detail.theme"
    >
        <span>{{ $version }}</span>
        <span class="mx-1">·</span>
        <a href="{{ $docsUrl }}" target="_blank" rel="noopener noreferrer" class="text-accent transition hover:underline" title="Открыть документацию">Документация</a>
        <button
            type="button"
            class="ml-auto inline-flex h-7 w-12 items-center rounded-full border border-border-sidebar bg-sidebar-hover p-0.5 text-text-sidebar-secondary transition hover:text-text-sidebar focus:outline-none focus:ring-2 focus:ring-accent/60"
            x-on:click="window.adminTheme.toggle(); theme = window.adminTheme.get();"
            :aria-pressed="theme === 'dark'"
            :title="theme === 'dark' ? 'Включить светлую тему' : 'Включить тёмную тему'"
        >
            <span
                class="flex h-6 w-6 items-center justify-center rounded-full bg-bg-primary text-[13px] shadow transition-transform"
                :class="theme === 'dark' ? 'translate-x-5 text-accent' : 'translate-x-0 text-text-secondary'"
                aria-hidden="true"
            >
                <span x-show="theme !== 'dark'">☀</span>
                <span x-show="theme === 'dark'">☾</span>
            </span>
            <span class="sr-only" x-text="theme === 'dark' ? 'Включить светлую тему' : 'Включить тёмную тему'"></span>
        </button>
    </div>
</aside>

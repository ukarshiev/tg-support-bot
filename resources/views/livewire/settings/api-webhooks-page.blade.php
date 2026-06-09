<div class="p-6 lg:p-8">

    {{-- ── Page header + add button ────────────────────────────────────────────── --}}
    <div class="mb-6 flex items-start justify-between gap-3">
        <div class="min-w-0">
            <h1 class="text-2xl font-bold text-text-primary">API и вебхуки</h1>
            <p class="mt-1 text-sm text-text-secondary">Управление API-ключами и настройка вебхуков для интеграции</p>
        </div>

        <x-admin.button-primary
            type="button"
            wire:click="addSource"
            wire:loading.attr="disabled"
            wire:target="addSource"
            class="shrink-0"
        >
            <svg xmlns="http://www.w3.org/2000/svg" class="mr-1.5 h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
            </svg>
            <span wire:loading.remove wire:target="addSource">Добавить источник</span>
            <span wire:loading wire:target="addSource">Создание...</span>
        </x-admin.button-primary>
    </div>

    {{-- Creation error --}}
    @if ($addError)
        <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $addError }}</div>
    @endif

    {{-- ── Sources table card ───────────────────────────────────────────────────── --}}
    <div class="rounded-xl border border-border-light bg-bg-primary">

        {{-- Card header --}}
        <div class="flex items-center px-6 py-4">
            <h2 class="text-base font-semibold text-text-primary">Источники</h2>
        </div>
        <div class="border-t border-border-light"></div>

        {{-- Column headers — hidden on mobile --}}
        <div class="hidden grid-cols-[1fr_280px_140px_60px] items-center bg-[#FAFAFA] px-6 py-3 text-[12px] font-medium text-text-secondary lg:grid">
            <span>Источник</span>
            <span>Вебхук</span>
            <span>Статус</span>
            <span class="text-center">Действия</span>
        </div>
        <div class="hidden border-t border-border-light lg:block"></div>

        {{-- Source rows --}}
        @forelse ($sources as $source)
            @php
                /** @var \App\Models\ExternalSource $source */
                $hasActiveToken = $source->accessTokens->isNotEmpty();
                $hasWebhook     = ! empty($source->webhook_url);
                $editUrl        = route('admin.settings.api-webhooks.source', $source->id);
                $avatarColor    = $this->avatarColor($source);
                $initials       = $this->avatarInitials($source);
            @endphp

            {{-- Divider (not before first row) --}}
            @if (! $loop->first)
                <div class="border-t border-border-light"></div>
            @endif

            {{-- Desktop: grid row --}}
            <div class="hidden grid-cols-[1fr_280px_140px_60px] items-center px-6 py-3.5 transition hover:bg-bg-secondary/40 lg:grid">

                {{-- Source column: icon tile + name + id --}}
                <a href="{{ $editUrl }}" class="flex items-center gap-3 min-w-0">
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-[12px] font-semibold text-white"
                        style="background: {{ $avatarColor }}"
                        aria-hidden="true"
                    >{{ $initials }}</div>
                    <div class="min-w-0">
                        <p class="truncate text-[13px] font-medium text-text-primary">{{ $source->name }}</p>
                        <p class="truncate text-[12px] text-text-secondary">ID: {{ $source->id }}</p>
                    </div>
                </a>

                {{-- Webhook column --}}
                <div class="min-w-0">
                    @if ($hasWebhook)
                        <span class="truncate text-[13px] text-text-primary" title="{{ $source->webhook_url }}">{{ $source->webhook_url }}</span>
                    @else
                        <span class="text-[13px] text-text-secondary">Не задан</span>
                    @endif
                </div>

                {{-- Status column --}}
                <div>
                    @if ($hasActiveToken)
                        <span class="inline-flex items-center rounded-md px-2.5 py-1 text-[12px] font-medium"
                              style="background:#DCFCE7; color:#15803D">
                            Активен
                        </span>
                    @else
                        <span class="inline-flex items-center rounded-md px-2.5 py-1 text-[12px] font-normal"
                              style="background:#F3F4F6; color:#6B7280">
                            Нет токена
                        </span>
                    @endif
                </div>

                {{-- Actions column --}}
                <div class="flex items-center justify-center">
                    <button
                        type="button"
                        wire:click="deleteSource({{ $source->id }})"
                        wire:confirm="Удалить источник «{{ $source->name }}»? Токен и настройки будут удалены без возможности восстановления."
                        class="flex h-8 w-8 items-center justify-center rounded-lg text-text-secondary transition hover:bg-bg-secondary hover:text-red-500"
                        title="Удалить источник"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                        </svg>
                    </button>
                </div>
            </div>

            {{-- Mobile: card row --}}
            <div class="flex items-center gap-2 px-4 py-3.5 lg:hidden">
                <a href="{{ $editUrl }}" class="flex min-w-0 flex-1 items-center gap-3 transition hover:opacity-80">
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-[12px] font-semibold text-white"
                        style="background: {{ $avatarColor }}"
                        aria-hidden="true"
                    >{{ $initials }}</div>
                    <div class="min-w-0">
                        <p class="truncate text-[13px] font-medium text-text-primary">{{ $source->name }}</p>
                        @if ($hasWebhook)
                            <p class="truncate text-[12px] text-text-secondary">{{ $source->webhook_url }}</p>
                        @else
                            <p class="text-[12px] text-text-secondary">Вебхук не задан</p>
                        @endif
                    </div>
                </a>
                <div class="flex shrink-0 items-center gap-1">
                    @if ($hasActiveToken)
                        <span class="inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-medium"
                              style="background:#DCFCE7; color:#15803D">Активен</span>
                    @else
                        <span class="inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-normal"
                              style="background:#F3F4F6; color:#6B7280">Нет токена</span>
                    @endif
                    <button
                        type="button"
                        wire:click="deleteSource({{ $source->id }})"
                        wire:confirm="Удалить источник «{{ $source->name }}»? Токен и настройки будут удалены без возможности восстановления."
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-text-secondary transition hover:bg-bg-secondary hover:text-red-500"
                        title="Удалить источник"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                        </svg>
                    </button>
                </div>
            </div>

        @empty
            {{-- Empty state --}}
            <div class="px-6 py-12 text-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="mx-auto mb-3 h-8 w-8 text-text-secondary" fill="none"
                     viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
                <p class="text-sm text-text-secondary">Источников пока нет. Нажмите «Добавить источник», чтобы создать первый.</p>
            </div>
        @endforelse

    </div>

</div>

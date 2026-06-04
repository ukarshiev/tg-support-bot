<div class="p-6 lg:p-8">

    {{-- ── Page header ──────────────────────────────────────────────────────────── --}}
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-text-primary">API и вебхуки</h1>
        <p class="mt-1 text-sm text-text-secondary">Управление API-ключами и настройка вебхуков для интеграции</p>
    </div>

    {{-- ── Add source card ─────────────────────────────────────────────────────── --}}
    <div class="mb-6 rounded-xl border border-border-light bg-bg-primary p-6 lg:px-7">
        <h2 class="mb-4 text-base font-semibold text-text-primary">Добавить источник</h2>

        {{-- Form row: name + button — desktop side-by-side, mobile stacked --}}
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center">
            {{-- Name field --}}
            <div class="flex-1 space-y-1.5">
                <label for="newSourceName" class="block text-[13px] font-medium text-text-primary">Название источника</label>
                <input
                    id="newSourceName"
                    type="text"
                    wire:model="newSourceName"
                    wire:keydown.enter="addSource"
                    placeholder="Например: CRM, Интернет-магазин"
                    autocomplete="off"
                    class="block w-full rounded-lg border bg-bg-primary px-3.5 py-2.5 text-sm text-text-primary placeholder-text-secondary outline-none transition focus:border-accent focus:ring-2 focus:ring-accent/20 {{ $addError ? 'border-red-400' : 'border-border-light' }}"
                />
                @if ($addError)
                    <p class="text-xs text-red-500">{{ $addError }}</p>
                @else
                    <p class="text-xs text-text-secondary">При создании автоматически выпускается bearer-токен</p>
                @endif
            </div>

            {{-- Submit button --}}
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
    </div>

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
                    <a
                        href="{{ $editUrl }}"
                        class="flex h-8 w-8 items-center justify-center rounded-lg text-text-secondary transition hover:bg-bg-secondary hover:text-text-primary"
                        title="Настроить источник"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 5v.01M12 12v.01M12 19v.01" />
                        </svg>
                    </a>
                </div>
            </div>

            {{-- Mobile: card row --}}
            <a href="{{ $editUrl }}" class="flex items-center justify-between px-4 py-3.5 transition hover:bg-bg-secondary/40 lg:hidden">
                <div class="flex items-center gap-3 min-w-0">
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
                </div>
                <div class="ml-3 flex shrink-0 items-center gap-2">
                    @if ($hasActiveToken)
                        <span class="inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-medium"
                              style="background:#DCFCE7; color:#15803D">Активен</span>
                    @else
                        <span class="inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-normal"
                              style="background:#F3F4F6; color:#6B7280">Нет токена</span>
                    @endif
                </div>
            </a>

        @empty
            {{-- Empty state --}}
            <div class="px-6 py-12 text-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="mx-auto mb-3 h-8 w-8 text-text-secondary" fill="none"
                     viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
                <p class="text-sm text-text-secondary">Внешние источники не созданы. Они появятся здесь после добавления через API.</p>
            </div>
        @endforelse

    </div>

</div>

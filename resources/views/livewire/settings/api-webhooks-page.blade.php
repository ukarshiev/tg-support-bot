<div class="p-6 lg:p-8">

    {{-- ── Page header ──────────────────────────────────────────────────────────── --}}
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-text-primary">API и вебхуки</h1>
            <p class="mt-1 text-sm text-text-secondary">Управление API-ключами и настройка вебхуков для интеграции</p>
        </div>
        @unless ($showAddForm)
            <x-admin.button-primary type="button" wire:click="showAddSourceForm" class="shrink-0">
                <span class="mr-1 text-base leading-none">+</span> Добавить источник
            </x-admin.button-primary>
        @endunless
    </div>

    {{-- ── Add source inline form ──────────────────────────────────────────────── --}}
    @if ($showAddForm)
        <div class="mb-6 flex flex-col gap-4 rounded-xl border border-border-light bg-bg-primary px-7 py-6">
            <h3 class="text-base font-semibold text-text-primary">Новый API-источник</h3>

            <div class="flex flex-col gap-1.5">
                <span class="text-[13px] font-medium text-text-primary">Название источника</span>
                <input
                    type="text"
                    wire:model="newSourceName"
                    wire:keydown.enter="addSource"
                    placeholder="Например: CRM, Интернет-магазин"
                    class="block w-full rounded-lg border border-border-light bg-bg-primary px-3.5 py-2.5 text-sm text-text-primary placeholder-text-secondary outline-none transition focus:border-accent focus:ring-2 focus:ring-accent/20 {{ $addError ? 'border-red-400' : '' }}"
                />
                @if ($addError)
                    <span class="text-xs text-red-600">{{ $addError }}</span>
                @else
                    <span class="text-xs text-text-secondary">При создании автоматически выпускается bearer-токен</span>
                @endif
            </div>

            <div class="flex items-center justify-end gap-3">
                <x-admin.button-secondary type="button" wire:click="cancelAddSource">Отмена</x-admin.button-secondary>
                <x-admin.button-primary type="button" wire:click="addSource" wire:loading.attr="disabled" wire:target="addSource">
                    <span wire:loading.remove wire:target="addSource">Создать</span>
                    <span wire:loading wire:target="addSource">Создание...</span>
                </x-admin.button-primary>
            </div>
        </div>
    @endif

    {{-- ── Sources list — vertical stack of link cards ────────────────────────── --}}
    <div class="space-y-3">

        @forelse ($sources as $source)
            @php
                /** @var \App\Models\ExternalSource $source */
                $hasActiveToken = $source->accessTokens->isNotEmpty();
                $hasWebhook     = ! empty($source->webhook_url);
            @endphp

            <a href="{{ route('admin.settings.api-webhooks.source', $source->id) }}"
               class="block rounded-xl border border-border-light bg-bg-primary p-4 transition hover:border-accent hover:shadow-sm">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        {{-- Icon tile --}}
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl" style="background:#EEF2FF">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-accent" fill="none"
                                 viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                      d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                        </div>
                        <div>
                            <p class="text-sm font-semibold text-text-primary">{{ $source->name }}</p>
                            <div class="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-0.5">
                                @if ($hasActiveToken)
                                    <span class="inline-flex items-center gap-1 text-xs font-medium" style="color:#34C759">
                                        <span class="inline-block h-1.5 w-1.5 rounded-full" style="background:#34C759"></span>
                                        Токен активен
                                    </span>
                                @else
                                    <span class="text-xs text-text-secondary">Нет токена</span>
                                @endif

                                @if ($hasWebhook)
                                    <span class="inline-flex items-center gap-1 text-xs font-medium" style="color:#34C759">
                                        <span class="inline-block h-1.5 w-1.5 rounded-full" style="background:#34C759"></span>
                                        Вебхук настроен
                                    </span>
                                @else
                                    <span class="text-xs text-text-secondary">Вебхук не задан</span>
                                @endif
                            </div>
                        </div>
                    </div>
                    {{-- Right chevron --}}
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0 text-text-secondary" fill="none"
                         viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                    </svg>
                </div>
            </a>

        @empty
            {{-- Empty state --}}
            <div class="rounded-xl border border-border-light bg-bg-primary px-7 py-12 text-center">
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

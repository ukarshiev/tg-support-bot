<div>

    {{-- ── Top bar — breadcrumb + bottom border ─────────────────────────────── --}}
    <div class="flex items-center border-b border-border-light bg-bg-primary px-10 py-0" style="height:64px">
        <div class="flex items-center gap-3">
            <a href="{{ route('admin.settings.api-webhooks') }}"
               class="flex items-center gap-1 text-text-secondary transition hover:text-text-primary"
               aria-label="Назад к API и вебхукам">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24"
                     stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 12H5m7-7-7 7 7 7" />
                </svg>
            </a>
            <a href="{{ route('admin.settings.api-webhooks') }}"
               class="text-sm text-text-secondary transition hover:text-text-primary">
                API и вебхуки
            </a>
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-text-secondary" fill="none"
                 viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
            </svg>
            <span class="text-sm font-semibold text-text-primary">{{ $sourceName }}</span>
        </div>
    </div>

    {{-- ── Notices ───────────────────────────────────────────────────────────── --}}
    @if ($tokenError)
        <div class="mx-8 mt-6 flex items-center gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0 text-red-500"
                 fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M12 9v2m0 4h.01M6.938 19h10.124A2 2 0 0019 16.27L13.938 7A2 2 0 0010.062 7L5 16.27A2 2 0 006.938 19z" />
            </svg>
            {{ $tokenError }}
        </div>
    @endif

    {{-- ── Two-column body ──────────────────────────────────────────────────── --}}
    <div class="grid grid-cols-1 gap-7 p-8 lg:grid-cols-[1fr_320px]">

        {{-- ── Form Card ────────────────────────────────────────────────────── --}}
        <div class="rounded-2xl border border-border-light bg-bg-primary" style="padding:28px 32px">

            {{-- Card header: icon + titles --}}
            <div class="flex items-center gap-3.5">
                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl" style="background:#EEF2FF">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-accent" fill="none"
                         viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                    </svg>
                </div>
                <div>
                    <h2 class="text-lg font-bold text-text-primary">{{ $sourceName }}</h2>
                    <p class="mt-0.5 text-xs text-text-secondary">Bearer-токен и вебхук внешнего источника</p>
                </div>
            </div>

            <div class="my-6 h-px bg-border-light"></div>

            {{-- ── API-ключ block ────────────────────────────────────────────── --}}
            <div class="space-y-4">

                <div class="flex flex-col gap-1.5">
                    <span class="text-[13px] font-medium text-text-primary">Ключ API</span>
                    <div class="flex items-center rounded-lg border border-border-light bg-bg-input px-3.5 py-2.5">
                        <span class="flex-1 truncate font-mono text-sm text-text-primary">
                            @if ($hasToken)
                                ••••••••••••••••••••••••<span class="text-text-secondary">{{ $tokenLast6 }}</span>
                            @else
                                <span class="italic text-text-secondary">токен не выпущен</span>
                            @endif
                        </span>
                    </div>
                    <span class="text-xs text-text-secondary">Используйте для интеграции с внешними сервисами</span>
                </div>

                {{-- One-time reveal banner --}}
                @if ($newToken)
                    <div class="flex items-start gap-3 rounded-lg border border-green-200 bg-green-50 px-4 py-3">
                        <svg xmlns="http://www.w3.org/2000/svg" class="mt-0.5 h-4 w-4 shrink-0 text-green-600"
                             fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                        </svg>
                        <div class="min-w-0 flex-1">
                            <p class="mb-1 text-xs font-semibold text-green-800">Новый токен сгенерирован. Скопируйте его — он больше не будет показан.</p>
                            <code class="block break-all rounded bg-green-100 px-2 py-1 font-mono text-xs text-green-900 select-all">{{ $newToken }}</code>
                        </div>
                        <button type="button"
                                wire:click="dismissNewToken"
                                class="shrink-0 text-green-500 transition hover:text-green-700"
                                aria-label="Закрыть">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24"
                                 stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                @endif

                {{-- Key actions --}}
                <div class="flex flex-wrap items-center gap-3">
                    @if ($hasToken)
                        <x-admin.button-secondary
                            type="button"
                            x-data
                            @click.prevent="if (navigator.clipboard) { navigator.clipboard.writeText('{{ $copyToken }}'); }"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="mr-1.5 h-4 w-4" fill="none"
                                 viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                      d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3" />
                            </svg>
                            Скопировать
                        </x-admin.button-secondary>
                    @endif

                    <x-admin.button-primary
                        type="button"
                        wire:click="regenerateToken"
                        wire:loading.attr="disabled"
                        wire:target="regenerateToken"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="mr-1.5 h-4 w-4" fill="none"
                             viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                  d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                        </svg>
                        <span wire:loading.remove wire:target="regenerateToken">Сгенерировать новый</span>
                        <span wire:loading wire:target="regenerateToken">Генерация...</span>
                    </x-admin.button-primary>
                </div>
            </div>

            <div class="my-6 h-px bg-border-light"></div>

            {{-- ── URL вебхука ───────────────────────────────────────────────── --}}
            <div class="space-y-5">

                <x-admin.form-field
                    label="URL вебхука"
                    for="webhook_url"
                    hint="URL для получения событий"
                    :error="$webhookError"
                >
                    <input
                        id="webhook_url"
                        type="url"
                        wire:model="webhookUrl"
                        placeholder="https://example.com/webhook"
                        class="block w-full rounded-lg border border-border-light bg-bg-input px-3.5 py-2.5 text-sm text-text-primary placeholder-text-secondary outline-none transition focus:border-accent focus:ring-2 focus:ring-accent/20
                            @if ($webhookError) border-red-400 @endif"
                    />
                </x-admin.form-field>

                {{-- Секретный ключ — design placeholder, no backend yet --}}
                <x-admin.form-field
                    label="Секретный ключ"
                    for="secret_key_placeholder"
                    hint="Для верификации входящих запросов · скоро"
                >
                    <input
                        id="secret_key_placeholder"
                        type="text"
                        disabled
                        placeholder="whsec_xxxxxxxxxxxxxxxx"
                        class="block w-full cursor-not-allowed rounded-lg border border-border-light bg-bg-input px-3.5 py-2.5 text-sm text-text-secondary placeholder-text-secondary opacity-70 outline-none"
                    />
                </x-admin.form-field>

            </div>

            {{-- ── События — design placeholder toggles, no backend yet ──────── --}}
            <div class="mt-5 flex flex-col gap-3 opacity-70">
                <span class="text-[13px] font-medium text-text-primary">
                    События <span class="font-normal text-text-secondary">· скоро</span>
                </span>

                @foreach ([['Новое сообщение', true], ['Закрытие обращения', true], ['Новый клиент', false], ['Ошибка доставки', false]] as [$eventLabel, $eventOn])
                    <div class="flex items-center justify-between">
                        <span class="text-[13px] text-text-primary">{{ $eventLabel }}</span>
                        <span aria-disabled="true"
                              class="relative flex h-6 w-10 shrink-0 cursor-not-allowed items-center rounded-full border-2 border-transparent p-0.5 {{ $eventOn ? 'bg-accent' : 'bg-border-light' }}">
                            <span class="h-5 w-5 rounded-full bg-white shadow {{ $eventOn ? 'translate-x-4' : 'translate-x-0' }}"></span>
                        </span>
                    </div>
                @endforeach
            </div>

            {{-- Actions row — right-aligned: «Отмена» + «Сохранить» --}}
            <div class="mt-6 flex items-center justify-end gap-3">
                <x-admin.button-secondary wire:click="cancel" type="button">
                    Отмена
                </x-admin.button-secondary>
                <x-admin.button-primary type="button" wire:click="saveWebhookUrl" wire:loading.attr="disabled" wire:target="saveWebhookUrl">
                    <span wire:loading.remove wire:target="saveWebhookUrl">Сохранить</span>
                    <span wire:loading wire:target="saveWebhookUrl">Сохранение...</span>
                </x-admin.button-primary>
            </div>

            {{-- Webhook save result notice --}}
            @if ($saved)
                <div class="mt-4 flex items-center gap-2 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0 text-green-500"
                         fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                    </svg>
                    URL вебхука сохранён.
                </div>
            @endif

        </div>

        {{-- ── Instruction panel (REST API reference) ──────────────────────── --}}
        <div>
            <div class="rounded-xl border border-border-light bg-bg-primary p-5 lg:p-6">

                {{-- Panel header --}}
                <div class="mb-5 flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-accent" fill="none"
                         viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                    <span class="text-sm font-semibold text-text-primary">REST API</span>
                </div>

                {{-- Base URL --}}
                <p class="mb-3 text-xs text-text-secondary">Базовый URL:</p>
                <code class="mb-4 block break-all rounded bg-bg-input px-2.5 py-1.5 font-mono text-xs text-text-primary">{{ rtrim(config('app.url'), '/') }}</code>

                {{-- Endpoint list --}}
                <div class="space-y-2">
                    @foreach ([
                        ['GET',    "/api/external/{$sourceId}/messages",  'Список сообщений'],
                        ['POST',   "/api/external/{$sourceId}/messages",  'Отправить сообщение'],
                        ['PUT',    "/api/external/{$sourceId}/messages",  'Обновить'],
                        ['DELETE', "/api/external/{$sourceId}/messages",  'Удалить'],
                        ['POST',   "/api/external/{$sourceId}/files",     'Загрузить файл'],
                    ] as [$method, $path, $label])
                        <div class="flex items-start gap-2">
                            <span class="mt-0.5 shrink-0 rounded px-1.5 py-0.5 font-mono text-[10px] font-bold
                                @if ($method === 'GET') bg-blue-100 text-blue-700
                                @elseif ($method === 'POST') bg-green-100 text-green-700
                                @elseif ($method === 'PUT') bg-yellow-100 text-yellow-700
                                @else bg-red-100 text-red-700 @endif">
                                {{ $method }}
                            </span>
                            <div class="min-w-0">
                                <code class="block break-all font-mono text-[11px] text-text-primary">{{ $path }}</code>
                                <span class="text-[11px] text-text-secondary">{{ $label }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>

                {{-- Auth note --}}
                <div class="mt-4 rounded-lg bg-bg-input px-3 py-2.5">
                    <p class="mb-1 text-[11px] font-semibold text-text-primary">Авторизация</p>
                    <code class="block font-mono text-[11px] text-text-secondary">Authorization: Bearer {token}</code>
                </div>

                {{-- Swagger link plate --}}
                <a href="/docs/swagger-v1-ui"
                   target="_blank"
                   rel="noopener noreferrer"
                   class="mt-5 flex items-center gap-2 rounded-lg px-3.5 py-3 text-xs font-medium text-accent transition hover:opacity-80"
                   style="background:#F0F4FF">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" fill="none"
                         viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    Открыть Swagger UI
                </a>

            </div>
        </div>

    </div>

</div>

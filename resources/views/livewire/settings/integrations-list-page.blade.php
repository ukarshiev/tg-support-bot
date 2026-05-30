{{-- Раздел «Интеграции»: список каналов поддержки (Telegram / VK / MAX / Виджет) --}}
<div class="p-4 lg:p-8 lg:max-w-3xl">

    {{-- ── Page header --}}
    <div class="mb-5">
        <h1 class="text-lg font-semibold text-text-primary lg:text-2xl lg:font-bold">Интеграции</h1>
        <p class="mt-0.5 text-sm text-text-secondary">Управление каналами поддержки</p>
    </div>

    {{-- ── Channel cards — vertical stack (matches x7EQB2) ──────────────────── --}}
    <div class="space-y-3">

        {{-- Telegram --}}
        <a href="{{ route('admin.settings.integrations.channel', ['channel' => 'telegram']) }}"
           class="block rounded-xl border border-border-light bg-bg-primary p-4 transition hover:border-accent hover:shadow-sm">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    {{-- Icon --}}
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl" style="background:#E0EDFF">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" style="color:#2AABEE"
                             viewBox="0 0 24 24" fill="currentColor">
                            <path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z" />
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-text-primary">Telegram</p>
                        @if ($channelStatuses['telegram']['connected'])
                            <span class="inline-flex items-center gap-1 text-xs font-medium" style="color:#34C759">
                                <span class="inline-block h-1.5 w-1.5 rounded-full" style="background:#34C759"></span>
                                Подключено
                            </span>
                        @else
                            <span class="text-xs text-text-secondary">Не подключён</span>
                        @endif
                    </div>
                </div>
                @if (! $channelStatuses['telegram']['connected'])
                    <span class="inline-flex items-center justify-center rounded-lg bg-accent px-3.5 py-1.5 text-xs font-medium text-white">
                        Подключить
                    </span>
                @endif
            </div>
            <p class="mt-3 text-[13px] leading-relaxed text-text-secondary">
                Поддержка через Telegram-бота: каждое обращение пользователя открывает отдельную тему в супергруппе поддержки.
            </p>
        </a>

        {{-- VK --}}
        <a href="{{ route('admin.settings.integrations.channel', ['channel' => 'vk']) }}"
           class="block rounded-xl border border-border-light bg-bg-primary p-4 transition hover:border-accent hover:shadow-sm">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl" style="background:#EEF2FF">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" style="color:#4680C2"
                             viewBox="0 0 24 24" fill="currentColor">
                            <path d="M15.684 0H8.316C1.592 0 0 1.592 0 8.316v7.368C0 22.408 1.592 24 8.316 24h7.368C22.408 24 24 22.408 24 15.684V8.316C24 1.592 22.391 0 15.684 0zm3.692 17.123h-1.744c-.66 0-.862-.523-2.049-1.713-1.033-1.01-1.49-1.135-1.744-1.135-.356 0-.458.102-.458.593v1.575c0 .424-.135.678-1.253.678-1.846 0-3.896-1.118-5.335-3.202C4.624 10.857 4.03 8.57 4.03 8.096c0-.254.102-.491.593-.491h1.744c.44 0 .61.203.779.678.864 2.49 2.303 4.675 2.896 4.675.22 0 .322-.102.322-.66V9.721c-.068-1.186-.695-1.287-.695-1.71 0-.203.17-.407.44-.407h2.744c.373 0 .508.203.508.643v3.473c0 .372.169.508.271.508.22 0 .407-.136.813-.542 1.253-1.406 2.151-3.574 2.151-3.574.119-.254.322-.491.763-.491h1.744c.525 0 .644.27.525.643-.22 1.017-2.354 4.031-2.354 4.031-.186.305-.254.44 0 .78.186.254.796.779 1.203 1.253.745.847 1.32 1.558 1.473 2.05.17.491-.085.745-.576.745z" />
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-text-primary">ВКонтакте</p>
                        @if ($channelStatuses['vk']['connected'])
                            <span class="inline-flex items-center gap-1 text-xs font-medium" style="color:#34C759">
                                <span class="inline-block h-1.5 w-1.5 rounded-full" style="background:#34C759"></span>
                                Подключено
                            </span>
                        @else
                            <span class="text-xs text-text-secondary">Не подключён</span>
                        @endif
                    </div>
                </div>
                @if (! $channelStatuses['vk']['connected'])
                    <span class="inline-flex items-center justify-center rounded-lg bg-accent px-3.5 py-1.5 text-xs font-medium text-white">
                        Подключить
                    </span>
                @endif
            </div>
            <p class="mt-3 text-[13px] leading-relaxed text-text-secondary">
                Поддержка через сообщество ВКонтакте: сообщения из чата сообщества поступают операторам как обращения.
            </p>
        </a>

        {{-- MAX --}}
        <a href="{{ route('admin.settings.integrations.channel', ['channel' => 'max']) }}"
           class="block rounded-xl border border-border-light bg-bg-primary p-4 transition hover:border-accent hover:shadow-sm">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl" style="background:#FFF3E0">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" style="color:#F57C00"
                             fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                  d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.14 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0" />
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-text-primary">Max</p>
                        @if ($channelStatuses['max']['connected'])
                            <span class="inline-flex items-center gap-1 text-xs font-medium" style="color:#34C759">
                                <span class="inline-block h-1.5 w-1.5 rounded-full" style="background:#34C759"></span>
                                Подключено
                            </span>
                        @else
                            <span class="text-xs text-text-secondary">Не подключён</span>
                        @endif
                    </div>
                </div>
                @if (! $channelStatuses['max']['connected'])
                    <span class="inline-flex items-center justify-center rounded-lg bg-accent px-3.5 py-1.5 text-xs font-medium text-white">
                        Подключить
                    </span>
                @endif
            </div>
            <p class="mt-3 text-[13px] leading-relaxed text-text-secondary">
                Поддержка через мессенджер MAX: обращения пользователей из MAX поступают операторам в общий поток.
            </p>
        </a>

        {{-- Виджет для сайта — disabled placeholder --}}
        <div class="cursor-not-allowed rounded-xl border border-border-light bg-bg-primary p-4 opacity-50"
             aria-disabled="true">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl" style="background:#EEF2FF">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-accent" fill="none"
                             viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                  d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17H3a2 2 0 01-2-2V5a2 2 0 012-2h14a2 2 0 012 2v10a2 2 0 01-2 2h-2" />
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-text-primary">Виджет для сайта</p>
                        <span class="text-xs text-text-secondary">Не подключён</span>
                    </div>
                </div>
                <span class="inline-flex items-center justify-center rounded-lg bg-bg-secondary px-3.5 py-1.5 text-xs font-medium text-text-secondary">
                    Скоро
                </span>
            </div>
            <p class="mt-3 text-[13px] leading-relaxed text-text-secondary">
                Виджет для онлайн-чата на сайте. Установите код на страницу.
            </p>
        </div>

    </div>

</div>

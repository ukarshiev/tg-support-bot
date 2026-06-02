<div>

    {{-- ── Top bar — breadcrumb + bottom border ─────────────────────────────── --}}
    <div class="flex items-center border-b border-border-light bg-bg-primary px-10 py-0" style="height:64px">
        <div class="flex items-center gap-3">
            <a href="{{ route('admin.settings.integrations') }}"
               class="flex items-center gap-1 text-text-secondary transition hover:text-text-primary"
               aria-label="Назад к интеграциям">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24"
                     stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 12H5m7-7-7 7 7 7" />
                </svg>
            </a>
            <a href="{{ route('admin.settings.integrations') }}"
               class="text-sm text-text-secondary transition hover:text-text-primary">
                Интеграции
            </a>
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-text-secondary" fill="none"
                 viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
            </svg>
            <span class="text-sm font-semibold text-text-primary">
                Подключение
                @if ($channel === 'telegram') Telegram
                @elseif ($channel === 'telegram_ai') Бот AI помощника
                @elseif ($channel === 'vk') ВКонтакте
                @else MAX
                @endif
            </span>
        </div>
    </div>

    {{-- ── Notices ───────────────────────────────────────────────────────────── --}}
    @if ($webhookMessage)
        <div class="mx-8 mt-6 flex items-center gap-2 rounded-xl border px-4 py-3 text-sm
            @if ($webhookSuccess) border-green-200 bg-green-50 text-green-800
            @else border-red-200 bg-red-50 text-red-800 @endif">
            @if ($webhookSuccess)
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0 text-green-500"
                     fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                </svg>
            @else
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0 text-red-500"
                     fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M6.938 19h10.124A2 2 0 0019 16.27L13.938 7A2 2 0 0010.062 7L5 16.27A2 2 0 006.938 19z" />
                </svg>
            @endif
            {{ $webhookMessage }}
        </div>
    @endif

    {{-- ── Two-column body ──────────────────────────────────────────────────── --}}
    <div class="grid grid-cols-1 gap-7 p-8 lg:grid-cols-[1fr_320px]">

        {{-- ── Form Card ────────────────────────────────────────────────────── --}}
        <div class="rounded-2xl border border-border-light bg-bg-primary" style="padding:28px 32px">

            {{-- Card header: icon + titles --}}
            <div class="flex items-center gap-3.5">
                @if ($channel === 'telegram')
                    <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl" style="background:#E0EDFF">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" style="color:#2AABEE"
                             viewBox="0 0 24 24" fill="currentColor">
                            <path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z" />
                        </svg>
                    </div>
                @elseif ($channel === 'telegram_ai')
                    <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl" style="background:#EEF9FF">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" style="color:#2AABEE"
                             fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                  d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" />
                        </svg>
                    </div>
                @elseif ($channel === 'vk')
                    <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl" style="background:#4C75A3">
                        <span class="text-lg font-bold text-white">VK</span>
                    </div>
                @else
                    <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl" style="background:#FFF3E0">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" style="color:#F57C00"
                             fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.14 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0" />
                        </svg>
                    </div>
                @endif
                <div>
                    <h2 class="text-lg font-bold text-text-primary">
                        @if ($channel === 'telegram') Подключить Telegram
                        @elseif ($channel === 'telegram_ai') Бот AI помощника
                        @elseif ($channel === 'vk') Подключить ВКонтакте
                        @else Подключить MAX
                        @endif
                    </h2>
                    <p class="mt-0.5 text-xs text-text-secondary">
                        @if ($channel === 'telegram') Настройте бота для приёма обращений
                        @elseif ($channel === 'telegram_ai') Отдельный бот ИИ-помощника для черновиков и автоответов
                        @elseif ($channel === 'vk') Настройте сообщество для приёма обращений
                        @else Настройте бота для приёма обращений из MAX
                        @endif
                    </p>
                </div>
            </div>

            <div class="my-6 h-px bg-border-light"></div>

            <form wire:submit="connect" novalidate>
                @csrf

                @if ($channel === 'telegram')

                    <div class="space-y-5">

                        {{-- ID группы --}}
                        <x-admin.form-field
                            label="ID группы"
                            for="telegram_group_id"
                            hint="ID Telegram-группы для приёма обращений"
                            :error="$formErrors['telegram_group_id'] ?? null"
                        >
                            <input
                                id="telegram_group_id"
                                type="text"
                                wire:model="telegram_group_id"
                                placeholder="-100XXXXXXXXXX"
                                class="block w-full rounded-lg border border-border-light bg-bg-input px-3.5 py-2.5 text-sm text-text-primary placeholder-text-secondary outline-none transition focus:border-accent focus:ring-2 focus:ring-accent/20
                                    @if (!empty($formErrors['telegram_group_id'])) border-red-400 @endif"
                            />
                        </x-admin.form-field>

                        {{-- Токен бота --}}
                        <x-admin.form-field
                            label="Токен бота"
                            for="telegram_token"
                            hint="Токен от @BotFather"
                            :error="$formErrors['telegram_token'] ?? null"
                        >
                            <input
                                id="telegram_token"
                                type="password"
                                wire:model="telegram_token"
                                autocomplete="new-password"
                                placeholder="110201543:AAHdqTcvCH1vGWJxfSeo..."
                                class="block w-full rounded-lg border border-border-light bg-bg-input px-3.5 py-2.5 text-sm text-text-primary placeholder-text-secondary outline-none transition focus:border-accent focus:ring-2 focus:ring-accent/20
                                    @if (!empty($formErrors['telegram_token'])) border-red-400 @endif"
                            />
                        </x-admin.form-field>

                        {{-- Секретный ключ Webhook --}}
                        <x-admin.form-field
                            label="Секретный ключ Webhook"
                            for="telegram_secret_key"
                            hint="Для верификации запросов от Telegram"
                            :error="$formErrors['telegram_secret_key'] ?? null"
                        >
                            <input
                                id="telegram_secret_key"
                                type="password"
                                wire:model="telegram_secret_key"
                                autocomplete="new-password"
                                placeholder="your-secret-key"
                                class="block w-full rounded-lg border border-border-light bg-bg-input px-3.5 py-2.5 text-sm text-text-primary placeholder-text-secondary outline-none transition focus:border-accent focus:ring-2 focus:ring-accent/20
                                    @if (!empty($formErrors['telegram_secret_key'])) border-red-400 @endif"
                            />
                        </x-admin.form-field>

                        {{-- Шаблон названия топика --}}
                        <x-admin.form-field
                            label="Шаблон названия топика"
                            for="telegram_template_topic_name"
                            hint="Например: {first_name} {last_name} {platform}"
                            :error="$formErrors['telegram_template_topic_name'] ?? null"
                        >
                            <input
                                id="telegram_template_topic_name"
                                type="text"
                                wire:model="telegram_template_topic_name"
                                placeholder="{first_name} {last_name} {platform}"
                                class="block w-full rounded-lg border border-border-light bg-bg-input px-3.5 py-2.5 text-sm text-text-primary placeholder-text-secondary outline-none transition focus:border-accent focus:ring-2 focus:ring-accent/20"
                            />
                        </x-admin.form-field>

                    </div>

                @elseif ($channel === 'telegram_ai')

                    <div class="space-y-5">

                        {{-- Токен AI-бота --}}
                        <x-admin.form-field
                            label="Токен AI-бота"
                            for="telegram_ai_token"
                            hint="Токен отдельного бота для черновиков и автоответов ИИ"
                            :error="$formErrors['telegram_ai_token'] ?? null"
                        >
                            <input
                                id="telegram_ai_token"
                                type="password"
                                wire:model="telegram_ai_token"
                                autocomplete="new-password"
                                placeholder="Оставьте пустым, чтобы не менять"
                                class="block w-full rounded-lg border border-border-light bg-bg-input px-3.5 py-2.5 text-sm text-text-primary placeholder-text-secondary outline-none transition focus:border-accent focus:ring-2 focus:ring-accent/20"
                            />
                        </x-admin.form-field>

                        {{-- Секретный ключ Webhook AI-бота --}}
                        <x-admin.form-field
                            label="Секретный ключ Webhook"
                            for="telegram_ai_secret"
                            hint="Для верификации входящих запросов от AI-бота"
                            :error="$formErrors['telegram_ai_secret'] ?? null"
                        >
                            <input
                                id="telegram_ai_secret"
                                type="password"
                                wire:model="telegram_ai_secret"
                                autocomplete="new-password"
                                placeholder="Оставьте пустым, чтобы не менять"
                                class="block w-full rounded-lg border border-border-light bg-bg-input px-3.5 py-2.5 text-sm text-text-primary placeholder-text-secondary outline-none transition focus:border-accent focus:ring-2 focus:ring-accent/20"
                            />
                        </x-admin.form-field>

                        {{-- ID AI-бота --}}
                        <x-admin.form-field
                            label="ID AI-бота"
                            for="telegram_ai_id"
                            hint="Числовой Telegram ID AI-бота"
                            :error="$formErrors['telegram_ai_id'] ?? null"
                        >
                            <input
                                id="telegram_ai_id"
                                type="text"
                                wire:model="telegram_ai_id"
                                placeholder="987654321"
                                class="block w-full rounded-lg border border-border-light bg-bg-input px-3.5 py-2.5 text-sm text-text-primary placeholder-text-secondary outline-none transition focus:border-accent focus:ring-2 focus:ring-accent/20"
                            />
                        </x-admin.form-field>

                        {{-- Username AI-бота --}}
                        <x-admin.form-field
                            label="Username AI-бота"
                            for="telegram_ai_username"
                            hint="Например @my_ai_bot"
                            :error="$formErrors['telegram_ai_username'] ?? null"
                        >
                            <input
                                id="telegram_ai_username"
                                type="text"
                                wire:model="telegram_ai_username"
                                placeholder="@my_ai_bot"
                                class="block w-full rounded-lg border border-border-light bg-bg-input px-3.5 py-2.5 text-sm text-text-primary placeholder-text-secondary outline-none transition focus:border-accent focus:ring-2 focus:ring-accent/20"
                            />
                        </x-admin.form-field>

                    </div>

                @elseif ($channel === 'vk')

                    <div class="space-y-5">

                        {{-- Токен --}}
                        <x-admin.form-field
                            label="Токен"
                            for="vk_token"
                            hint="Токен доступа сообщества"
                            :error="$formErrors['vk_token'] ?? null"
                        >
                            <input
                                id="vk_token"
                                type="password"
                                wire:model="vk_token"
                                autocomplete="new-password"
                                placeholder="vk1.a.xxxxxxxxxxxx"
                                class="block w-full rounded-lg border border-border-light bg-bg-input px-3.5 py-2.5 text-sm text-text-primary placeholder-text-secondary outline-none transition focus:border-accent focus:ring-2 focus:ring-accent/20"
                            />
                        </x-admin.form-field>

                        {{-- Секретный ключ Webhook --}}
                        <x-admin.form-field
                            label="Секретный ключ Webhook"
                            for="vk_secret_key"
                            hint="Для верификации входящих запросов"
                            :error="$formErrors['vk_secret_key'] ?? null"
                        >
                            <input
                                id="vk_secret_key"
                                type="password"
                                wire:model="vk_secret_key"
                                autocomplete="new-password"
                                placeholder="your-secret-key"
                                class="block w-full rounded-lg border border-border-light bg-bg-input px-3.5 py-2.5 text-sm text-text-primary placeholder-text-secondary outline-none transition focus:border-accent focus:ring-2 focus:ring-accent/20"
                            />
                        </x-admin.form-field>

                        {{-- Код подтверждения --}}
                        <x-admin.form-field
                            label="Код подтверждения"
                            for="vk_confirm_code"
                            hint="Строка подтверждения сервера из настроек Callback API"
                            :error="$formErrors['vk_confirm_code'] ?? null"
                        >
                            <input
                                id="vk_confirm_code"
                                type="password"
                                wire:model="vk_confirm_code"
                                autocomplete="new-password"
                                placeholder="abc123"
                                class="block w-full rounded-lg border border-border-light bg-bg-input px-3.5 py-2.5 text-sm text-text-primary placeholder-text-secondary outline-none transition focus:border-accent focus:ring-2 focus:ring-accent/20"
                            />
                        </x-admin.form-field>

                    </div>

                @else {{-- max --}}

                    <div class="space-y-5">

                        {{-- Токен --}}
                        <x-admin.form-field
                            label="Токен"
                            for="max_token"
                            hint="Токен бота из настроек MAX"
                            :error="$formErrors['max_token'] ?? null"
                        >
                            <input
                                id="max_token"
                                type="password"
                                wire:model="max_token"
                                autocomplete="new-password"
                                placeholder="max-bot-token-xxxxxxxxxxxx"
                                class="block w-full rounded-lg border border-border-light bg-bg-input px-3.5 py-2.5 text-sm text-text-primary placeholder-text-secondary outline-none transition focus:border-accent focus:ring-2 focus:ring-accent/20"
                            />
                        </x-admin.form-field>

                        {{-- Секретный ключ Webhook --}}
                        <x-admin.form-field
                            label="Секретный ключ Webhook"
                            for="max_secret_key"
                            hint="Для верификации входящих запросов от MAX"
                            :error="$formErrors['max_secret_key'] ?? null"
                        >
                            <input
                                id="max_secret_key"
                                type="password"
                                wire:model="max_secret_key"
                                autocomplete="new-password"
                                placeholder="your-secret-key"
                                class="block w-full rounded-lg border border-border-light bg-bg-input px-3.5 py-2.5 text-sm text-text-primary placeholder-text-secondary outline-none transition focus:border-accent focus:ring-2 focus:ring-accent/20"
                            />
                        </x-admin.form-field>

                    </div>

                @endif

                {{-- Actions row — right-aligned: «Отмена» + primary action --}}
                <div class="mt-6 flex items-center justify-end gap-3">
                    <x-admin.button-secondary wire:click="cancel" type="button">
                        Отмена
                    </x-admin.button-secondary>
                    @if ($channel === 'telegram_ai')
                        <x-admin.button-primary type="submit" wire:loading.attr="disabled" wire:target="connect">
                            <span wire:loading.remove wire:target="connect">Сохранить</span>
                            <span wire:loading wire:target="connect">Сохранение...</span>
                        </x-admin.button-primary>
                    @else
                        <x-admin.button-primary type="submit" wire:loading.attr="disabled" wire:target="connect">
                            <span wire:loading.remove wire:target="connect">Подключить</span>
                            <span wire:loading wire:target="connect">Подключение...</span>
                        </x-admin.button-primary>
                    @endif
                </div>

            </form>
        </div>

        {{-- ── Instruction panel ────────────────────────────────────────────── --}}
        <div>
            <div class="rounded-xl border border-border-light bg-bg-primary p-5 lg:p-6">

                {{-- Panel header --}}
                <div class="mb-5 flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-accent" fill="none"
                         viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                    </svg>
                    <span class="text-sm font-semibold text-text-primary">Инструкция</span>
                </div>

                {{-- Numbered steps --}}
                <ol class="space-y-3">
                    @php
                        $steps = match ($channel) {
                            'telegram' => [
                                'Создайте группу в Telegram',
                                'Создайте бота в Telegram через @botFather',
                                'Добавьте бота как администратора в группу',
                                'Включите "Темы" в настройках группы',
                            ],
                            'telegram_ai' => [
                                'Создайте второй бот через @BotFather (это AI-бот)',
                                'Добавьте AI-бота как администратора в ту же супергруппу',
                                'Сохраните токен и секретный ключ на этой странице',
                                'Зарегистрируйте вебхук командой: docker exec -it pet php artisan ai-bot:set-webhook',
                            ],
                            'vk' => [
                                'Создайте сообщество VK или откройте существующее',
                                'Перейдите в настройки сообщества Управление → Дополнительно → Работа с API',
                                'Нажмите "Создать ключ"',
                                'Во вкладке "Callback API" укажите адрес "'.rtrim(config('app.url'), '/').'/vk/bot"',
                                'Укажите "Секретный ключ"',
                                'Нажмите "Подтвердить"',
                            ],
                            default => [
                                'Создайте бота в платформе MAX',
                                'Скопируйте токен из настроек бота',
                                'Укажите URL вебхука в настройках',
                            ],
                        };

                        $docsUrl = match ($channel) {
                            'telegram' => 'https://docs.tg-support-bot.ru/docs/telegram-bot.html',
                            'telegram_ai' => 'https://docs.tg-support-bot.ru/docs/ai-bot.html',
                            'vk' => 'https://docs.tg-support-bot.ru/docs/vk-group.html',
                            'max' => 'https://docs.tg-support-bot.ru/docs/max-bot.html',
                            default => 'https://docs.tg-support-bot.ru/',
                        };
                    @endphp
                    @foreach ($steps as $i => $step)
                        <li class="flex items-start gap-3">
                            <span class="flex h-[22px] w-[22px] shrink-0 items-center justify-center rounded-full text-[11px] font-semibold text-accent"
                                  style="background:#EEF2FF">
                                {{ $i + 1 }}
                            </span>
                            <span class="text-[13px] leading-relaxed text-text-secondary">{{ $step }}</span>
                        </li>
                    @endforeach
                </ol>

                {{-- Docs link plate --}}
                <a href="{{ $docsUrl }}"
                   target="_blank"
                   rel="noopener noreferrer"
                   class="mt-5 flex items-center gap-2 rounded-lg px-3.5 py-3 text-xs font-medium text-accent transition hover:opacity-80"
                   style="background:#F0F4FF">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" fill="none"
                         viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    Подробнее в документации
                </a>

            </div>
        </div>

    </div>

</div>

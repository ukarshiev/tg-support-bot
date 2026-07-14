<div class="p-6 lg:p-8">
    @include('livewire.settings.partials.language-tabs', ['active' => $activeTab])

    @if($saved)
        <div class="mb-4 rounded-xl border border-green-500/30 bg-green-500/10 px-4 py-3 text-sm text-green-300">
            Настройки сохранены.
        </div>
    @endif

    @if($activeTab === 'languages')
        <div class="rounded-xl border border-border-light bg-bg-primary">
            <div class="flex flex-col gap-3 border-b border-border-light px-5 py-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h2 class="text-base font-semibold text-text-primary">Список языков</h2>
                    <p class="mt-1 text-sm text-text-secondary">
                        Страница {{ $languagePage }} из {{ $languagePagesCount }} · всего языков: {{ count($languages) }}.
                    </p>
                </div>

                <div class="flex flex-wrap gap-2" role="navigation" aria-label="Пагинация языков">
                    @for($page = 1; $page <= $languagePagesCount; $page++)
                        <button
                            type="button"
                            wire:click="setLanguagePage({{ $page }})"
                            title="Открыть страницу {{ $page }} списка языков"
                            class="rounded-lg border px-3 py-2 text-sm font-semibold transition {{ $languagePage === $page ? 'border-accent bg-accent text-white' : 'border-border-light text-text-secondary hover:border-accent hover:text-text-primary' }}"
                        >
                            Страница {{ $page }}
                        </button>
                    @endfor
                </div>
            </div>

            <div class="hidden grid-cols-[80px_1fr_1fr_120px_150px_120px] gap-3 border-b border-border-light px-5 py-3 text-xs font-semibold text-text-secondary lg:grid">
                <span>Порядок</span>
                <span>Название</span>
                <span>Код</span>
                <span>Включён</span>
                <span>При старте</span>
                <span>Статус</span>
            </div>

            @foreach($paginatedLanguages as $row)
                @php
                    $index = $row['index'];
                    $language = $row['language'];
                @endphp
                <div class="grid gap-3 border-b border-border-light px-5 py-4 lg:grid-cols-[80px_1fr_1fr_120px_150px_120px] lg:items-center">
                    <input type="number" min="1" wire:model="languages.{{ $index }}.sort_order" title="Порядок кнопки при выборе языка"
                        class="h-10 rounded-lg border border-border-light bg-bg-primary px-3 text-sm text-text-primary">
                    <input type="text" wire:model="languages.{{ $index }}.native" title="Название языка на родном языке"
                        class="h-10 rounded-lg border border-border-light bg-bg-primary px-3 text-sm text-text-primary">
                    <div class="flex gap-2">
                        <input type="text" wire:model="languages.{{ $index }}.code" title="Код языка"
                            class="h-10 w-24 rounded-lg border border-border-light bg-bg-primary px-3 text-sm text-text-primary">
                        <input type="text" wire:model="languages.{{ $index }}.name" title="Название языка для оператора"
                            class="h-10 min-w-0 flex-1 rounded-lg border border-border-light bg-bg-primary px-3 text-sm text-text-primary">
                    </div>
                    <label class="inline-flex items-center gap-2 text-sm text-text-primary" title="Включить язык в системе">
                        <input type="checkbox" wire:model="languages.{{ $index }}.enabled">
                        Включён
                    </label>
                    <label class="inline-flex items-center gap-2 text-sm text-text-primary" title="Показывать кнопку языка при старте бота">
                        <input type="checkbox" wire:model="languages.{{ $index }}.show_on_start">
                        Показывать
                    </label>
                    <span class="text-xs text-text-secondary">{{ $language['enabled'] ? 'Активен' : 'Выключен' }}</span>
                </div>
            @endforeach
        </div>

        <div class="mt-5 flex justify-end">
            <x-admin.button-primary type="button" wire:click="saveLanguages" title="Сохранить языки">
                Сохранить языки
            </x-admin.button-primary>
        </div>
    @endif

    @if($activeTab === 'providers')
        <div class="grid gap-5 xl:grid-cols-[1fr_420px]">
            <div class="space-y-4">
                <div class="rounded-xl border border-border-light bg-bg-primary p-5">
                    <h2 class="text-base font-semibold text-text-primary">Приоритет провайдеров</h2>
                    <p class="mt-1 text-sm text-text-secondary">Если первый провайдер упал или лимит исчерпан, система попробует следующий.</p>

                    <div class="mt-4 space-y-2">
                        @foreach($providerOrder as $index => $provider)
                            <div class="flex flex-col gap-3 rounded-lg border border-border-light px-4 py-3 lg:flex-row lg:items-center lg:justify-between">
                                <label class="inline-flex items-start gap-3" title="Проверять перевод через {{ ucfirst($provider) }}">
                                    <input
                                        type="radio"
                                        wire:model="testProvider"
                                        value="{{ $provider }}"
                                        title="Выбрать {{ ucfirst($provider) }} для кнопки проверки перевода"
                                        class="mt-1"
                                    >
                                    <span>
                                        <span class="block text-sm font-semibold text-text-primary">{{ ucfirst($provider) }}</span>
                                        <span class="block text-xs text-text-secondary">
                                            Проверка: {{ $testProvider === $provider ? 'выбран' : 'не выбран' }} · Сегодня: {{ $providerStats[$provider]['today'] ?? 0 }} симв. · Месяц: {{ $providerStats[$provider]['month'] ?? 0 }} симв.
                                        </span>
                                    </span>
                                </label>
                                <div class="flex gap-2">
                                    <button type="button" wire:click="moveProviderUp({{ $index }})" title="Поднять провайдера выше"
                                        class="rounded-lg border border-border-light px-3 py-1 text-sm text-text-secondary">↑</button>
                                    <button type="button" wire:click="moveProviderDown({{ $index }})" title="Опустить провайдера ниже"
                                        class="rounded-lg border border-border-light px-3 py-1 text-sm text-text-secondary">↓</button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="rounded-xl border border-border-light bg-bg-primary p-5">
                    <h2 class="text-base font-semibold text-text-primary">Доступ и безопасность</h2>
                    <label class="mt-4 flex items-start gap-3 text-sm text-text-primary" title="Разрешить отправку клиентских текстов в облачные переводчики">
                        <input type="checkbox" wire:model="allowExternal" class="mt-1">
                        <span>
                            Разрешить внешние переводчики
                            <span class="block text-xs text-text-secondary">Если выключено, Yandex/Google не получают клиентские тексты.</span>
                        </span>
                    </label>
                </div>

                <div class="rounded-xl border border-border-light bg-bg-primary p-5">
                    <h2 class="text-base font-semibold text-text-primary">Ключи провайдеров</h2>
                    <div class="mt-4 grid gap-4">
                        <input type="text" wire:model.live.debounce.250ms="yandexApiKey" autocomplete="off" spellcheck="false" title="Введите или проверьте текущий API-ключ Yandex Translate"
                            placeholder="Yandex API key"
                            data-provider-key="yandex"
                            class="h-11 rounded-lg border border-border-light bg-bg-primary px-3 text-sm text-text-primary">
                        <input type="text" wire:model="yandexFolderId" title="Введите Yandex folderId"
                            placeholder="Yandex folderId"
                            class="h-11 rounded-lg border border-border-light bg-bg-primary px-3 text-sm text-text-primary">
                        <input type="text" wire:model.live.debounce.250ms="googleApiKey" autocomplete="off" spellcheck="false" title="Введите или проверьте текущий API-ключ Google Translate"
                            placeholder="Google API key"
                            data-provider-key="google"
                            class="h-11 rounded-lg border border-border-light bg-bg-primary px-3 text-sm text-text-primary">
                        <input type="url" wire:model="offlineEndpoint" title="Endpoint будущего offline-переводчика"
                            placeholder="Offline endpoint, например http://translator:8080"
                            class="h-11 rounded-lg border border-border-light bg-bg-primary px-3 text-sm text-text-primary">
                    </div>
                </div>
            </div>

            <div class="rounded-xl border border-border-light bg-bg-primary p-5">
                <h2 class="text-base font-semibold text-text-primary">Тест перевода</h2>
                <textarea wire:model="testText" rows="4" title="Введите русский текст для проверки перевода"
                    class="mt-4 w-full rounded-lg border border-border-light bg-bg-primary p-3 text-sm text-text-primary"></textarea>
                <select wire:model="testTargetLocale" title="Выберите язык проверки"
                    class="mt-3 h-11 w-full rounded-lg border border-border-light bg-bg-primary px-3 text-sm text-text-primary">
                    @foreach($languages as $language)
                        <option value="{{ $language['code'] }}">{{ $language['native'] }} ({{ $language['code'] }})</option>
                    @endforeach
                </select>
                <button type="button"
                    x-on:click='$wire.testTranslation(document.querySelector("[data-provider-key=yandex]")?.value || "", document.querySelector("[data-provider-key=google]")?.value || "")'
                    title="Проверить перевод через выбранного радиокнопкой провайдера"
                    class="mt-3 w-full rounded-lg bg-accent px-4 py-2.5 text-sm font-semibold text-white">
                    Проверить перевод через {{ ucfirst($testProvider) }}
                </button>

                @if($testResult)
                    <div class="mt-4 rounded-lg border border-green-500/30 bg-green-500/10 p-3 text-sm text-green-300">{{ $testResult }}</div>
                @endif
                @if($testError)
                    <div class="mt-4 rounded-lg border border-red-500/30 bg-red-500/10 p-3 text-sm text-red-300">{{ $testError }}</div>
                @endif
            </div>
        </div>

        <div class="mt-5 flex justify-end">
            <button type="button"
                x-on:click='$wire.saveProviders(document.querySelector("[data-provider-key=yandex]")?.value || "", document.querySelector("[data-provider-key=google]")?.value || "")'
                title="Сохранить провайдеры перевода"
                class="rounded-lg bg-accent px-5 py-3 text-sm font-semibold text-white transition hover:bg-accent-hover">
                Сохранить провайдеры
            </button>
        </div>
    @endif
</div>

<div x-data="{
    insertVariable(fieldId, property, token) {
        const field = document.getElementById(fieldId);
        if (!field) return;
        const start = field.selectionStart ?? field.value.length;
        const end = field.selectionEnd ?? field.value.length;
        const next = field.value.slice(0, start) + token + field.value.slice(end);
        field.value = next;
        $wire.set(property, next);
        this.$nextTick(() => {
            field.focus();
            field.setSelectionRange(start + token.length, start + token.length);
        });
    }
}">
    <div class="p-4 lg:p-8">
        <div class="mb-5 flex items-center gap-3">
            <a href="{{ route('admin.settings.auto-replies') }}" title="Вернуться к списку автоответов"
               class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-border-light text-text-secondary transition hover:bg-bg-secondary hover:text-text-primary"
               aria-label="К списку автоответов">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M19 12H5m7-7-7 7 7 7" />
                </svg>
            </a>
            <h1 class="text-lg font-bold text-text-primary lg:text-2xl">{{ $isEdit ? 'Редактирование автоответа' : 'Новый автоответ' }}</h1>
        </div>

        <div class="space-y-5 rounded-xl border border-border-light bg-bg-primary p-4 lg:p-6">
            <h2 class="text-base font-semibold text-text-primary">Параметры правила</h2>

            <x-admin.form-field label="Тип" for="type" hint="Назначение шаблона: обычный, приветствие, завершение или бан" :error="$errors->first('type')">
                <select id="type" wire:model="type" title="Выберите тип автоответа" class="block h-[42px] w-full rounded-lg border border-border-light bg-bg-primary px-3.5 text-sm text-text-primary outline-none transition focus:border-accent focus:ring-2 focus:ring-accent/20">
                    @foreach($typeLabels as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </x-admin.form-field>

            <x-admin.form-field label="Триггер" for="trigger" hint="Слово или фраза для активации автоответа" :error="$errors->first('trigger')">
                <input id="trigger" type="text" wire:model="trigger" title="Введите фразу-триггер" placeholder="Например: Привет" autocomplete="off" class="block h-[42px] w-full rounded-lg border bg-bg-primary px-3.5 text-sm text-text-primary placeholder-text-secondary outline-none transition focus:border-accent focus:ring-2 focus:ring-accent/20 {{ $errors->has('trigger') ? 'border-red-400' : 'border-border-light' }}" />
            </x-admin.form-field>

            <x-admin.form-field label="Текст ответа" for="response" hint="Поддерживается Markdown. Переменные connector/paybot не переводятся машинным переводчиком." :error="$errors->first('response')">
                <div class="mb-2 flex flex-wrap items-center gap-2">
                    <span class="text-xs font-semibold text-text-secondary">Вставить:</span>
                    @forelse($variables as $variable)
                        <button type="button" title="Вставить переменную {{ $variable->key }}" @click="insertVariable('response', 'response', '{{ '{' . '{' . $variable->key . '}' . '}' }}')" class="rounded-lg border border-border-light px-3 py-1.5 text-xs font-semibold text-text-primary hover:border-accent hover:text-accent">
                            {{ $variable->name }}
                        </button>
                    @empty
                        <span class="text-xs text-text-secondary">Создайте переменные во вкладке «Переменные».</span>
                    @endforelse
                    @foreach($clientVariables as $variable)
                        <button type="button" title="Вставить переменную клиента {{ $variable }}" @click="insertVariable('response', 'response', '{{ '{' . $variable . '}' }}')" class="rounded-lg border border-border-light px-3 py-1.5 text-xs font-semibold text-text-primary hover:border-accent hover:text-accent">
                            {{ '{' . $variable . '}' }}
                        </button>
                    @endforeach
                </div>
                <textarea id="response" wire:model="response" title="Введите русский базовый текст автоответа" rows="5" placeholder="Введите текст автоматического ответа..." class="block h-[140px] w-full resize-none rounded-lg border bg-bg-primary p-3.5 text-sm text-text-primary placeholder-text-secondary outline-none transition focus:border-accent focus:ring-2 focus:ring-accent/20 {{ $errors->has('response') ? 'border-red-400' : 'border-border-light' }}"></textarea>
            </x-admin.form-field>

            <div class="flex items-center justify-between gap-3 pt-1">
                <span class="text-sm font-medium text-text-primary">Активировать</span>
                <x-admin.toggle wire:model="enabled" />
            </div>
        </div>

        <div class="mt-5 space-y-5 rounded-xl border border-border-light bg-bg-primary p-4 lg:p-6">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h2 class="text-base font-semibold text-text-primary">Переводы</h2>
                    <p class="mt-1 text-sm text-text-secondary">Русский текст — источник. Остальные языки можно перевести автоматически и поправить вручную.</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <label class="inline-flex items-center gap-2 text-xs text-text-secondary" title="Разрешить перезаписать ручные правки при автопереводе">
                        <input type="checkbox" wire:model="overwriteManualTranslations" title="Перезаписать ручные правки">
                        Перезаписать ручные правки
                    </label>
                    <button type="button" wire:click="translateAllLanguages" wire:loading.attr="disabled" wire:target="translateAllLanguages" title="Запустить перевод на все включённые языки" class="inline-flex items-center gap-2 rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-white transition disabled:cursor-wait disabled:opacity-70">
                        <span wire:loading.remove wire:target="translateAllLanguages">Перевести все языки</span>
                        <span wire:loading wire:target="translateAllLanguages">Ставим в очередь...</span>
                    </button>
                </div>
            </div>

            <div class="grid gap-4 lg:grid-cols-[260px_1fr]">
                <div>
                    <label class="mb-2 block text-sm font-medium text-text-primary" for="selectedLocale">Язык</label>
                    <select id="selectedLocale" wire:model.live="selectedLocale" title="Выберите язык перевода" class="h-11 w-full rounded-lg border border-border-light bg-bg-primary px-3 text-sm text-text-primary">
                        @foreach($languages as $code => $language)
                            @if($code !== 'ru')
                                <option value="{{ $code }}">{{ $language['native'] }} — {{ $translationStatuses[$code] ?? 'empty' }}</option>
                            @endif
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-2 block text-sm font-medium text-text-primary" for="selectedTranslationText">Текст выбранного языка</label>
                    <div class="mb-2 flex flex-wrap items-center gap-2">
                        <span class="text-xs font-semibold text-text-secondary">Вставить:</span>
                        @forelse($variables as $variable)
                            <button type="button" title="Вставить переменную {{ $variable->key }}" @click="insertVariable('selectedTranslationText', 'selectedTranslationText', '{{ '{' . '{' . $variable->key . '}' . '}' }}')" class="rounded-lg border border-border-light px-3 py-1.5 text-xs font-semibold text-text-primary hover:border-accent hover:text-accent">
                                {{ $variable->name }}
                            </button>
                        @empty
                            <span class="text-xs text-text-secondary">Создайте переменные во вкладке «Переменные».</span>
                        @endforelse
                        @foreach($clientVariables as $variable)
                            <button type="button" title="Вставить переменную клиента {{ $variable }}" @click="insertVariable('selectedTranslationText', 'selectedTranslationText', '{{ '{' . $variable . '}' }}')" class="rounded-lg border border-border-light px-3 py-1.5 text-xs font-semibold text-text-primary hover:border-accent hover:text-accent">
                                {{ '{' . $variable . '}' }}
                            </button>
                        @endforeach
                    </div>
                    <textarea id="selectedTranslationText" wire:model="selectedTranslationText" rows="5" title="Отредактируйте перевод вручную" class="w-full rounded-lg border border-border-light bg-bg-primary p-3 text-sm text-text-primary"></textarea>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button type="button" wire:click="saveSelectedTranslation" title="Сохранить ручную правку перевода" class="rounded-lg border border-border-light px-4 py-2 text-sm font-semibold text-text-primary">Сохранить перевод</button>
                        <button type="button" wire:click="previewSelectedTranslation" title="Проверить финальный текст с переменными" class="rounded-lg border border-border-light px-4 py-2 text-sm font-semibold text-text-primary">Проверить перевод</button>
                        <button type="button" wire:click="translateSelectedLanguage" title="Перевести только выбранный язык" class="rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-white">Перевести этот язык</button>
                    </div>
                </div>
            </div>
        </div>

        @if($showTranslationPreview)
            <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
                <div class="w-full max-w-2xl rounded-xl border border-border-light bg-bg-primary p-5 shadow-xl">
                    <div class="mb-4 flex items-center justify-between gap-3">
                        <h2 class="text-lg font-semibold text-text-primary">Предпросмотр перевода</h2>
                        <button type="button" wire:click="closeTranslationPreview" title="Закрыть предпросмотр" class="rounded-lg border border-border-light px-3 py-1 text-sm text-text-primary">Закрыть</button>
                    </div>
                    @if($translationPreviewWarnings)
                        <div class="mb-3 rounded-lg border border-red-400/40 bg-red-500/10 p-3 text-sm text-red-500">
                            @foreach($translationPreviewWarnings as $warning)
                                <div>{{ $warning }}</div>
                            @endforeach
                        </div>
                    @endif
                    <pre class="max-h-[420px] whitespace-pre-wrap rounded-lg border border-border-light bg-bg-secondary p-4 text-sm text-text-primary">{{ $translationPreviewText }}</pre>
                </div>
            </div>
        @endif

        <div class="mt-6 flex items-center justify-end gap-3">
            <x-admin.button-primary type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save" title="Сохранить автоответ">
                <span wire:loading.remove wire:target="save">Сохранить</span>
                <span wire:loading wire:target="save">Сохраняем...</span>
            </x-admin.button-primary>
        </div>
    </div>
</div>

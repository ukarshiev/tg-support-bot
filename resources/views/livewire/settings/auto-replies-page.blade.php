<div class="p-6 lg:p-8">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-text-primary">Автоответы</h1>
        <p class="mt-1 text-sm text-text-secondary">Настройте автоматические ответы на частые вопросы и переменные для безопасного перевода</p>
    </div>

    <div class="mb-5 flex flex-wrap gap-2 border-b border-border-light">
        <button type="button" wire:click="setTab('auto-replies')" title="Показать автоответы"
            class="-mb-px rounded-t-lg px-4 py-2 text-sm font-semibold transition {{ $activeTab === 'auto-replies' ? 'border-b-2 border-accent text-accent' : 'text-text-secondary hover:text-text-primary' }}">
            Автоответы
        </button>
        <button type="button" wire:click="setTab('variables')" title="Показать переменные"
            class="-mb-px rounded-t-lg px-4 py-2 text-sm font-semibold transition {{ $activeTab === 'variables' ? 'border-b-2 border-accent text-accent' : 'text-text-secondary hover:text-text-primary' }}">
            Переменные
        </button>
    </div>

    @if ($activeTab === 'auto-replies')
        <div class="mb-4 flex items-center justify-between gap-3">
            <span class="text-sm text-text-secondary">{{ $this->rulesCountLabel(count($rules)) }}</span>

            <a href="{{ route('admin.settings.auto-replies.create') }}"
               title="Добавить правило"
               class="inline-flex shrink-0 items-center justify-center rounded-[10px] bg-accent px-5 py-2.5 text-sm font-medium text-white transition hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="mr-1.5 h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                </svg>
                Добавить правило
            </a>
        </div>

        <div class="rounded-xl border border-border-light bg-bg-primary">
            <div class="hidden grid-cols-[180px_180px_1fr_80px] items-center bg-[#FAFAFA] px-6 py-3 text-[12px] font-medium text-text-secondary lg:grid">
                <span>Триггер</span>
                <span>Тип</span>
                <span>Ответ</span>
                <span class="text-center">Действия</span>
            </div>
            <div class="hidden border-t border-border-light lg:block"></div>

            @forelse ($rules as $rule)
                @if (! $loop->first)
                    <div class="border-t border-border-light"></div>
                @endif

                <div class="hidden grid-cols-[180px_180px_1fr_80px] items-center px-6 py-3.5 lg:grid">
                    <div class="flex min-w-0 items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0 text-accent" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z" />
                        </svg>
                        <span class="truncate text-sm font-medium text-text-primary">{{ $rule->trigger }}</span>
                    </div>

                    <span class="text-[13px] text-text-secondary">{{ \App\Models\AutoReply::typeLabels()[$rule->type] ?? $rule->type }}</span>
                    <p class="min-w-0 truncate pr-6 text-[13px] text-text-secondary">{{ $rule->response }}</p>

                    <div class="flex items-center justify-center gap-3">
                        <a href="{{ route('admin.settings.auto-replies.edit', ['rule' => $rule->id]) }}"
                           class="flex h-8 w-8 items-center justify-center rounded-lg text-text-secondary transition hover:bg-bg-secondary hover:text-accent" title="Редактировать">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M17 3a2.828 2.828 0 114 4L7.5 20.5 2 22l1.5-5.5L17 3z" />
                            </svg>
                        </a>
                        <button type="button" wire:click="deleteRule({{ $rule->id }})" wire:confirm="Удалить правило «{{ $rule->trigger }}»?"
                                class="flex h-8 w-8 items-center justify-center rounded-lg text-text-secondary transition hover:bg-bg-secondary hover:text-red-500" title="Удалить">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="flex flex-col gap-2 px-4 py-3.5 lg:hidden">
                    <div class="flex min-w-0 items-center gap-2">
                        <span class="truncate text-sm font-medium text-text-primary">{{ $rule->trigger }}</span>
                    </div>
                    <p class="text-[13px] text-text-secondary">{{ $rule->response }}</p>
                    <span class="text-[12px] text-text-secondary">Тип: {{ \App\Models\AutoReply::typeLabels()[$rule->type] ?? $rule->type }}</span>
                    <div class="flex items-center gap-4 pt-1">
                        <a href="{{ route('admin.settings.auto-replies.edit', ['rule' => $rule->id]) }}" title="Редактировать" class="text-[12px] font-medium text-text-secondary transition hover:text-accent">Изменить</a>
                        <button type="button" wire:click="deleteRule({{ $rule->id }})" wire:confirm="Удалить правило «{{ $rule->trigger }}»?" title="Удалить" class="text-[12px] font-medium text-text-secondary transition hover:text-red-500">Удалить</button>
                    </div>
                </div>
            @empty
                <div class="px-6 py-12 text-center">
                    <p class="text-sm text-text-secondary">Правил пока нет. Добавьте первое правило.</p>
                </div>
            @endforelse
        </div>
    @else
        <div class="grid gap-5 xl:grid-cols-[420px_1fr]">
            <form wire:submit="saveVariable" class="space-y-4 rounded-xl border border-border-light bg-bg-primary p-4 lg:p-6">
                <h2 class="text-base font-semibold text-text-primary">{{ $editingVariableId ? 'Редактирование переменной' : 'Новая переменная' }}</h2>
                <x-admin.form-field label="Ключ" for="variableKey" hint="Например: connector или paybot" :error="$errors->first('variableKey')">
                    <input id="variableKey" type="text" wire:model.blur="variableKey" title="Введите ключ переменной" placeholder="connector" class="block h-11 w-full rounded-lg border border-border-light bg-bg-primary px-3 text-sm text-text-primary">
                </x-admin.form-field>
                <x-admin.form-field label="Название" for="variableName" :error="$errors->first('variableName')">
                    <input id="variableName" type="text" wire:model="variableName" title="Введите понятное название" placeholder="Ссылка на канал Connector" class="block h-11 w-full rounded-lg border border-border-light bg-bg-primary px-3 text-sm text-text-primary">
                </x-admin.form-field>
                <x-admin.form-field label="Значение" for="variableValue" :error="$errors->first('variableValue')">
                    <textarea id="variableValue" wire:model="variableValue" rows="3" title="Введите значение переменной" placeholder="https://t.me/relaxa_massage" class="block w-full rounded-lg border border-border-light bg-bg-primary p-3 text-sm text-text-primary"></textarea>
                </x-admin.form-field>
                <x-admin.form-field label="Описание" for="variableDescription" :error="$errors->first('variableDescription')">
                    <textarea id="variableDescription" wire:model="variableDescription" rows="2" title="Введите подсказку для оператора" placeholder="Где использовать эту переменную" class="block w-full rounded-lg border border-border-light bg-bg-primary p-3 text-sm text-text-primary"></textarea>
                </x-admin.form-field>
                <label class="inline-flex items-center gap-2 text-sm text-text-secondary" title="Включить переменную для вставки и подстановки">
                    <input type="checkbox" wire:model="variableEnabled" title="Включить переменную">
                    Активна
                </label>
                <div class="flex flex-wrap gap-2">
                    <button type="submit" title="Сохранить переменную" class="rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-white">Сохранить переменную</button>
                    <button type="button" wire:click="resetVariableForm" title="Очистить форму" class="rounded-lg border border-border-light px-4 py-2 text-sm font-semibold text-text-primary">Очистить</button>
                </div>
            </form>

            <div class="rounded-xl border border-border-light bg-bg-primary">
                <div class="grid grid-cols-[160px_1fr_120px] gap-3 border-b border-border-light px-4 py-3 text-xs font-semibold text-text-secondary lg:grid-cols-[180px_220px_1fr_120px]">
                    <span>Ключ</span><span class="hidden lg:block">Название</span><span>Значение</span><span>Действия</span>
                </div>
                @forelse($variables as $variable)
                    <div class="grid grid-cols-[160px_1fr_120px] gap-3 border-b border-border-light px-4 py-3 text-sm lg:grid-cols-[180px_220px_1fr_120px]">
                        <code class="text-accent">&#123;&#123;{{ $variable->key }}&#125;&#125;</code>
                        <span class="hidden text-text-primary lg:block">{{ $variable->name }}</span>
                        <span class="truncate text-text-secondary">{{ $variable->value }}</span>
                        <span class="flex gap-2">
                            <button type="button" wire:click="editVariable({{ $variable->id }})" title="Редактировать переменную" class="text-accent">Изменить</button>
                            <button type="button" wire:click="deleteVariable({{ $variable->id }})" wire:confirm="Удалить переменную {{ $variable->key }}?" title="Удалить переменную" class="text-red-500">Удалить</button>
                        </span>
                    </div>
                @empty
                    <div class="px-4 py-8 text-center text-sm text-text-secondary">Переменных пока нет.</div>
                @endforelse
            </div>
        </div>
    @endif
</div>

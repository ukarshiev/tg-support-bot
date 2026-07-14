<div class="p-4 lg:p-8" x-data="{ drawerOpen: @entangle('drawerOpen') }" x-on:keydown.escape.window="$wire.closeDrawer()">
    {{-- Header --}}
    <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div class="min-w-0">
            <h1 class="text-2xl font-bold text-text-primary">База знаний AI</h1>
            <p class="mt-1 text-sm text-text-secondary">Управляйте блоками знаний, которые AI использует вместо длинного системного промпта</p>
        </div>

        @if ($activeTab === 'blocks')
            <button
                type="button"
                wire:click="openCreate"
                class="inline-flex shrink-0 items-center justify-center rounded-[10px] bg-accent px-5 py-2.5 text-sm font-medium text-white transition hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-2"
                title="Добавить блок знаний"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="mr-1.5 h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                </svg>
                Добавить блок
            </button>
        @endif
    </div>

    {{-- Tabs --}}
    <div class="mb-4 overflow-x-auto rounded-xl border border-border-light bg-bg-primary p-1" role="tablist" aria-label="Разделы базы знаний AI">
        <div class="flex min-w-max gap-1">
            <button
                type="button"
                wire:click="setActiveTab('support')"
                class="rounded-lg px-4 py-2 text-sm font-medium transition {{ $activeTab === 'support' ? 'bg-accent text-white' : 'text-text-secondary hover:bg-bg-secondary hover:text-text-primary' }}"
                title="Открыть support-диалоги"
                role="tab"
                aria-selected="{{ $activeTab === 'support' ? 'true' : 'false' }}"
            >
                Support-диалоги
            </button>
            <button
                type="button"
                wire:click="setActiveTab('blocks')"
                class="rounded-lg px-4 py-2 text-sm font-medium transition {{ $activeTab === 'blocks' ? 'bg-accent text-white' : 'text-text-secondary hover:bg-bg-secondary hover:text-text-primary' }}"
                title="Открыть блоки знаний"
                role="tab"
                aria-selected="{{ $activeTab === 'blocks' ? 'true' : 'false' }}"
            >
                Блоки знаний
            </button>
            <button
                type="button"
                wire:click="setActiveTab('moderation')"
                class="rounded-lg px-4 py-2 text-sm font-medium transition {{ $activeTab === 'moderation' ? 'bg-accent text-white' : 'text-text-secondary hover:bg-bg-secondary hover:text-text-primary' }}"
                title="Открыть правила AI-модерации"
                role="tab"
                aria-selected="{{ $activeTab === 'moderation' ? 'true' : 'false' }}"
            >
                AI-модератор
            </button>
        </div>
    </div>

    {{-- Notices --}}
    @if ($successMessage)
        <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700" title="Успешное действие">
            {{ $successMessage }}
        </div>
    @endif

    @if ($errorMessage)
        <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" title="Ошибка действия">
            {{ $errorMessage }}
        </div>
    @endif

    @if ($activeTab === 'support')
        {{-- Support archive RAG --}}
        <div class="mb-4 overflow-hidden rounded-xl border border-border-light bg-bg-primary">
            <div class="flex flex-col gap-3 px-4 py-4 lg:flex-row lg:items-start lg:justify-between lg:px-6">
                <div>
                    <h2 class="text-base font-semibold text-text-primary">Support-диалоги</h2>
                    <p class="mt-1 text-sm text-text-secondary">Старые обращения используются как похожие примеры для AI-ответа. AI берёт только статус «Активен».</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button
                        type="button"
                        wire:click="openFillAiModal"
                        wire:loading.attr="disabled"
                        wire:target="openFillAiModal"
                        class="inline-flex shrink-0 items-center justify-center rounded-[10px] bg-accent px-4 py-2.5 text-sm font-medium text-white transition hover:bg-accent/90 disabled:opacity-60"
                        title="Пополнить базу AI новыми диалогами"
                    >
                        <span wire:loading.remove wire:target="openFillAiModal">Пополнить базу AI</span>
                        <span wire:loading wire:target="openFillAiModal">Считаю…</span>
                    </button>
                    <button
                        type="button"
                        wire:click="openArchiveImportModal"
                        wire:loading.attr="disabled"
                        wire:target="openArchiveImportModal"
                        class="inline-flex shrink-0 items-center justify-center rounded-[10px] border border-border-light px-4 py-2.5 text-sm font-medium text-text-primary transition hover:bg-bg-secondary disabled:opacity-60"
                        title="Пополнить базу AI из папки архива"
                    >
                        <span wire:loading.remove wire:target="openArchiveImportModal">Из архива</span>
                        <span wire:loading wire:target="openArchiveImportModal">Считаю…</span>
                    </button>
                    <button
                        type="button"
                        wire:click="rebuildSupportChunks"
                        wire:loading.attr="disabled"
                        wire:target="rebuildSupportChunks"
                        class="inline-flex shrink-0 items-center justify-center rounded-[10px] border border-border-light px-4 py-2.5 text-sm font-medium text-text-primary transition hover:bg-bg-secondary disabled:opacity-60"
                        title="Переиндексировать support-фрагменты"
                    >
                        <span wire:loading.remove wire:target="rebuildSupportChunks">Переиндексировать</span>
                        <span wire:loading wire:target="rebuildSupportChunks">Индексирую…</span>
                    </button>
                    <button
                        type="button"
                        wire:click="translateAllSupportChunks"
                        wire:loading.attr="disabled"
                        wire:target="translateAllSupportChunks"
                        class="inline-flex shrink-0 items-center justify-center rounded-[10px] border border-blue-200 bg-blue-50 px-4 py-2.5 text-sm font-medium text-blue-700 transition hover:bg-blue-100 disabled:opacity-60"
                        title="Поставить все support-кейсы в очередь RU canonical"
                    >
                        <span wire:loading.remove wire:target="translateAllSupportChunks">Перевести все на RU</span>
                        <span wire:loading wire:target="translateAllSupportChunks">Ставлю…</span>
                    </button>
                </div>
            </div>

            <div class="grid gap-3 border-t border-border-light px-4 py-4 sm:grid-cols-2 lg:grid-cols-4 lg:px-6">
                <div class="rounded-xl bg-bg-secondary px-4 py-3" title="Количество импортированных сообщений">
                    <p class="text-xs text-text-secondary">Сообщения</p>
                    <p class="mt-1 text-xl font-semibold text-text-primary">{{ $this->supportMessagesCount }}</p>
                </div>
                <div class="rounded-xl bg-bg-secondary px-4 py-3" title="Количество активных кейсов, доступных AI">
                    <p class="text-xs text-text-secondary">Активные</p>
                    <p class="mt-1 text-xl font-semibold text-emerald-600">{{ $this->activeSupportChunksCount }}</p>
                </div>
                <div class="rounded-xl bg-bg-secondary px-4 py-3" title="Количество кейсов на ручной проверке">
                    <p class="text-xs text-text-secondary">Нужно проверить</p>
                    <p class="mt-1 text-xl font-semibold text-amber-600">{{ $this->reviewSupportChunksCount }}</p>
                </div>
                <div class="rounded-xl bg-bg-secondary px-4 py-3" title="Количество выключенных кейсов">
                    <p class="text-xs text-text-secondary">Выключенные</p>
                    <p class="mt-1 text-xl font-semibold text-text-secondary">{{ $this->disabledSupportChunksCount }}</p>
                </div>
            </div>

            <div class="grid gap-3 border-t border-border-light px-4 py-4 sm:grid-cols-2 lg:grid-cols-5 lg:px-6">
                @foreach ($this->supportTranslationStats as $status => $count)
                    <div class="rounded-xl bg-blue-50 px-4 py-3" title="Количество RU canonical полей со статусом {{ $status }}">
                        <p class="text-xs text-blue-700">{{ (new \App\Models\AiSupportKnowledgeChunk())->translationStatusLabel($status) }}</p>
                        <p class="mt-1 text-lg font-semibold text-blue-900">{{ $count }}</p>
                    </div>
                @endforeach
            </div>

            <div class="border-t border-border-light px-4 py-4 lg:px-6">
                <div class="grid gap-3 lg:grid-cols-[1fr_210px_190px]">
                    <x-admin.form-field label="Поиск" for="support_search">
                        <input
                            id="support_search"
                            type="search"
                            wire:model.live.debounce.350ms="supportSearch"
                            class="w-full rounded-[10px] border border-border-light bg-bg-primary px-3 py-2.5 text-sm text-text-primary outline-none transition focus:border-accent focus:ring-2 focus:ring-accent/20"
                            placeholder="Клиент, оператор, причина, hash или дубль"
                            title="Введите текст для поиска по support-кейсам"
                        >
                    </x-admin.form-field>

                    <x-admin.form-field label="Статус" for="support_status_filter">
                        <select
                            id="support_status_filter"
                            wire:model.live="supportStatusFilter"
                            class="w-full rounded-[10px] border border-border-light bg-bg-primary px-3 py-2.5 text-sm text-text-primary outline-none transition focus:border-accent focus:ring-2 focus:ring-accent/20"
                            title="Выберите статус support-кейсов"
                        >
                            <option value="all">Все</option>
                            <option value="active">Активен</option>
                            <option value="review">Нужно проверить</option>
                            <option value="disabled">Выключен</option>
                        </select>
                    </x-admin.form-field>

                    <x-admin.form-field label="Сортировка" for="support_sort_filter">
                        <select
                            id="support_sort_filter"
                            wire:model.live="supportSortField"
                            class="w-full rounded-[10px] border border-border-light bg-bg-primary px-3 py-2.5 text-sm text-text-primary outline-none transition focus:border-accent focus:ring-2 focus:ring-accent/20"
                            title="Выберите сортировку support-кейсов"
                        >
                            <option value="first_message_at">Дата создания</option>
                            <option value="last_message_at">Последнее сообщение</option>
                            <option value="created_at">Дата добавления</option>
                            <option value="status">Статус</option>
                        </select>
                    </x-admin.form-field>
                </div>
            </div>

            <div class="border-t border-border-light">
                @forelse ($supportChunks as $chunk)
                    @if (! $loop->first)
                        <div class="border-t border-border-light"></div>
                    @endif
                    <div class="grid gap-3 px-4 py-3 lg:grid-cols-[140px_1fr_1fr_150px_190px] lg:items-start lg:px-6">
                        <div class="space-y-1">
                            <span class="inline-flex rounded-full px-2.5 py-1 text-[11px] font-medium {{ $chunk->status === \App\Models\AiSupportKnowledgeChunk::STATUS_ACTIVE ? 'bg-emerald-50 text-emerald-700' : ($chunk->status === \App\Models\AiSupportKnowledgeChunk::STATUS_REVIEW ? 'bg-amber-50 text-amber-700' : 'bg-gray-100 text-gray-500') }}" title="Статус support-кейса">
                                {{ $chunk->statusLabel() }}
                            </span>
                            <p class="text-[11px] text-text-secondary" title="Дата создания кейса">
                                {{ $chunk->first_message_at?->format('d.m.Y H:i') ?? 'Без даты' }}
                            </p>
                            @if ($chunk->duplicate_group_key)
                                <p class="text-[11px] text-amber-700" title="Группа дублей">Дубль? {{ $chunk->duplicate_group_key }}</p>
                            @endif
                        </div>
                        <button type="button" wire:click="openSupportChunk({{ $chunk->id }})" class="min-w-0 text-left" title="Открыть карточку support-кейса">
                            <p class="text-xs font-medium text-text-secondary">Клиент</p>
                            <p class="mt-1 line-clamp-2 text-sm text-text-primary"><span class="font-medium">Оригинал:</span> {{ $chunk->originalQuestion() }}</p>
                            <p class="mt-1 line-clamp-2 text-sm text-blue-700"><span class="font-medium">RU:</span> {{ $chunk->question_ru ?: 'Ждёт RU canonical' }}</p>
                            <p class="mt-1 text-[11px] text-text-secondary" title="Статус перевода клиента">{{ $chunk->translationStatusLabel($chunk->question_translation_status) }}</p>
                        </button>
                        <button type="button" wire:click="openSupportChunk({{ $chunk->id }})" class="min-w-0 text-left" title="Открыть карточку support-кейса">
                            <p class="text-xs font-medium text-text-secondary">Оператор</p>
                            <p class="mt-1 line-clamp-2 text-sm text-text-primary"><span class="font-medium">Оригинал:</span> {{ $chunk->originalAnswer() }}</p>
                            <p class="mt-1 line-clamp-2 text-sm text-blue-700"><span class="font-medium">RU:</span> {{ $chunk->answer_ru ?: 'Ждёт RU canonical' }}</p>
                            <p class="mt-1 text-[11px] text-text-secondary" title="Статус перевода оператора">{{ $chunk->translationStatusLabel($chunk->answer_translation_status) }}</p>
                        </button>
                        <div class="min-w-0" title="Наличие инструкции AI">
                            @if ($chunk->ai_instruction)
                                <span class="inline-flex rounded-full bg-emerald-50 px-2.5 py-1 text-[11px] font-medium text-emerald-700">Есть инструкция</span>
                                <p class="mt-1 line-clamp-3 text-xs text-text-secondary">{{ $chunk->ai_instruction }}</p>
                            @else
                                <span class="inline-flex rounded-full bg-amber-50 px-2.5 py-1 text-[11px] font-medium text-amber-700">Нет инструкции</span>
                                <p class="mt-1 text-xs text-text-secondary">AI использует кейс только как пример.</p>
                            @endif
                        </div>
                        <div class="flex flex-wrap items-center gap-2 lg:justify-end">
                            <button type="button" wire:click="openSupportChunk({{ $chunk->id }})" class="rounded-lg px-3 py-1.5 text-xs font-medium text-accent transition hover:bg-bg-secondary" title="Открыть карточку support-кейса">Открыть</button>
                            <button type="button" wire:click="setSupportChunkStatus({{ $chunk->id }}, 'active')" class="rounded-lg px-3 py-1.5 text-xs font-medium text-emerald-700 transition hover:bg-emerald-50" title="Активировать support-кейс">Активен</button>
                            <button type="button" wire:click="setSupportChunkStatus({{ $chunk->id }}, 'review')" class="rounded-lg px-3 py-1.5 text-xs font-medium text-amber-700 transition hover:bg-amber-50" title="Отправить support-кейс на проверку">Проверить</button>
                            <button type="button" wire:click="setSupportChunkStatus({{ $chunk->id }}, 'disabled')" class="rounded-lg px-3 py-1.5 text-xs font-medium text-text-secondary transition hover:bg-bg-secondary" title="Выключить support-кейс">Выключить</button>
                            <button type="button" wire:click="deleteSupportChunk({{ $chunk->id }})" wire:confirm="Удалить support-кейс? Действие необратимо." class="rounded-lg px-3 py-1.5 text-xs font-medium text-red-600 transition hover:bg-red-50" title="Удалить support-кейс физически">Удалить</button>
                        </div>
                    </div>
                @empty
                    <div class="px-4 py-6 text-sm text-text-secondary lg:px-6">
                        Support-кейсов пока нет. Запустите импорт архива или пополнение базы AI.
                    </div>
                @endforelse
            </div>

            @if ($supportChunks->hasPages())
                <div class="border-t border-border-light px-4 py-3 lg:px-6">
                    {{ $supportChunks->links() }}
                </div>
            @endif
        </div>
    @endif

    @if ($activeTab === 'blocks')
    {{-- Stats --}}
    <div class="mb-4 grid gap-3 sm:grid-cols-3">
        <div class="rounded-xl border border-border-light bg-bg-primary px-4 py-3">
            <p class="text-xs text-text-secondary">Всего</p>
            <p class="mt-1 text-xl font-semibold text-text-primary">{{ $this->totalCount }}</p>
        </div>
        <div class="rounded-xl border border-border-light bg-bg-primary px-4 py-3">
            <p class="text-xs text-text-secondary">Активные</p>
            <p class="mt-1 text-xl font-semibold text-emerald-600">{{ $this->activeCount }}</p>
        </div>
        <div class="rounded-xl border border-border-light bg-bg-primary px-4 py-3">
            <p class="text-xs text-text-secondary">Выключенные</p>
            <p class="mt-1 text-xl font-semibold text-text-secondary">{{ $this->inactiveCount }}</p>
        </div>
    </div>

    {{-- Filters --}}
    <div class="mb-4 rounded-xl border border-border-light bg-bg-primary p-4">
        <div class="grid gap-3 lg:grid-cols-[1fr_190px_190px]">
            <x-admin.form-field label="Поиск" for="ai_knowledge_search">
                <input
                    id="ai_knowledge_search"
                    type="search"
                    wire:model.live.debounce.350ms="search"
                    class="w-full rounded-[10px] border border-border-light bg-bg-primary px-3 py-2.5 text-sm text-text-primary outline-none transition focus:border-accent focus:ring-2 focus:ring-accent/20"
                    placeholder="Название, slug, текст или ключевое слово"
                    title="Введите текст для поиска по базе знаний"
                >
            </x-admin.form-field>

            <x-admin.form-field label="Статус" for="ai_knowledge_status">
                <select
                    id="ai_knowledge_status"
                    wire:model.live="statusFilter"
                    class="w-full rounded-[10px] border border-border-light bg-bg-primary px-3 py-2.5 text-sm text-text-primary outline-none transition focus:border-accent focus:ring-2 focus:ring-accent/20"
                    title="Выберите фильтр активности"
                >
                    <option value="all">Все</option>
                    <option value="active">Активные</option>
                    <option value="inactive">Выключенные</option>
                </select>
            </x-admin.form-field>

            <x-admin.form-field label="Сортировка" for="ai_knowledge_sort">
                <select
                    id="ai_knowledge_sort"
                    wire:model.live="sortField"
                    class="w-full rounded-[10px] border border-border-light bg-bg-primary px-3 py-2.5 text-sm text-text-primary outline-none transition focus:border-accent focus:ring-2 focus:ring-accent/20"
                    title="Выберите поле сортировки"
                >
                    <option value="priority">Приоритет</option>
                    <option value="title">Название</option>
                    <option value="updated_at">Дата обновления</option>
                </select>
            </x-admin.form-field>
        </div>
    </div>

    {{-- Table --}}
    <div class="overflow-hidden rounded-xl border border-border-light bg-bg-primary">
        <div class="flex items-center justify-between gap-3 px-4 py-4 lg:px-6">
            <h2 class="text-base font-semibold text-text-primary">Блоки знаний</h2>
            <div wire:loading class="text-xs text-text-secondary">Обновляю…</div>
        </div>
        <div class="border-t border-border-light"></div>

        {{-- Desktop headers --}}
        <div class="admin-muted-surface hidden grid-cols-[110px_minmax(220px,1.4fr)_180px_minmax(180px,1fr)_90px_130px_110px] items-center bg-[#FAFAFA] px-6 py-3 text-[12px] font-medium text-text-secondary lg:grid">
            <span>Статус</span>
            <button type="button" wire:click="sortBy('title')" class="text-left transition hover:text-accent" title="Сортировать по названию">Название</button>
            <span>Slug</span>
            <span>Ключевые слова</span>
            <button type="button" wire:click="sortBy('priority')" class="text-left transition hover:text-accent" title="Сортировать по приоритету">Приоритет</button>
            <button type="button" wire:click="sortBy('updated_at')" class="text-left transition hover:text-accent" title="Сортировать по дате обновления">Обновлено</button>
            <span class="text-center">Действия</span>
        </div>
        <div class="hidden border-t border-border-light lg:block"></div>

        @forelse ($items as $item)
            @php
                /** @var \App\Models\AiKnowledgeItem $item */
                $keywords = array_slice($item->keywords ?? [], 0, 4);
                $keywordsRest = max(count($item->keywords ?? []) - count($keywords), 0);
            @endphp

            @if (! $loop->first)
                <div class="border-t border-border-light"></div>
            @endif

            {{-- Desktop row --}}
            <div class="hidden grid-cols-[110px_minmax(220px,1.4fr)_180px_minmax(180px,1fr)_90px_130px_110px] items-center px-6 py-3.5 transition hover:bg-bg-secondary/40 lg:grid">
                <div>
                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[12px] font-medium {{ $item->is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-500' }}">
                        {{ $item->is_active ? 'Активен' : 'Выключен' }}
                    </span>
                </div>

                <button type="button" wire:click="openView({{ $item->id }})" class="min-w-0 text-left" title="Открыть блок знаний">
                    <span class="block truncate text-[13px] font-semibold text-text-primary">{{ $item->title }}</span>
                    <span class="mt-0.5 block truncate text-[12px] text-text-secondary">{{ Str::limit($item->content, 90) }}</span>
                </button>

                <code class="truncate rounded bg-bg-secondary px-2 py-1 text-[12px] text-text-secondary" title="{{ $item->slug }}">{{ $item->slug }}</code>

                <div class="flex min-w-0 flex-wrap gap-1.5">
                    @forelse ($keywords as $keyword)
                        <span class="max-w-[120px] truncate rounded-md bg-bg-secondary px-2 py-1 text-[11px] text-text-secondary" title="{{ $keyword }}">{{ $keyword }}</span>
                    @empty
                        <span class="text-[12px] text-text-secondary">—</span>
                    @endforelse
                    @if ($keywordsRest > 0)
                        <span class="rounded-md bg-bg-secondary px-2 py-1 text-[11px] text-text-secondary" title="Остальные ключевые слова">+{{ $keywordsRest }}</span>
                    @endif
                </div>

                <span class="text-[13px] text-text-primary">{{ $item->priority }}</span>
                <span class="text-[12px] text-text-secondary">{{ $item->updated_at?->format('d.m.Y H:i') }}</span>

                <div class="flex items-center justify-center gap-1">
                    <button type="button" wire:click="openEdit({{ $item->id }})" class="flex h-8 w-8 items-center justify-center rounded-lg text-text-secondary transition hover:bg-bg-secondary hover:text-accent" title="Редактировать блок">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 3a2.828 2.828 0 114 4L7.5 20.5 2 22l1.5-5.5L17 3z" />
                        </svg>
                    </button>
                    <button type="button" wire:click="toggleActive({{ $item->id }})" class="flex h-8 w-8 items-center justify-center rounded-lg text-text-secondary transition hover:bg-bg-secondary hover:text-accent" title="{{ $item->is_active ? 'Выключить блок' : 'Включить блок' }}">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                        </svg>
                    </button>
                    <button type="button" wire:click="deleteItem({{ $item->id }})" wire:confirm="Удалить блок «{{ $item->title }}»? Действие необратимо." class="flex h-8 w-8 items-center justify-center rounded-lg text-text-secondary transition hover:bg-bg-secondary hover:text-red-500" title="Удалить блок">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                        </svg>
                    </button>
                </div>
            </div>

            {{-- Mobile card --}}
            <div class="space-y-3 px-4 py-4 lg:hidden">
                <div class="flex items-start justify-between gap-3">
                    <button type="button" wire:click="openView({{ $item->id }})" class="min-w-0 text-left" title="Открыть блок знаний">
                        <span class="block truncate text-sm font-semibold text-text-primary">{{ $item->title }}</span>
                        <span class="mt-0.5 block truncate text-xs text-text-secondary">{{ $item->slug }}</span>
                    </button>
                    <span class="shrink-0 rounded-full px-2.5 py-1 text-[11px] font-medium {{ $item->is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-500' }}">
                        {{ $item->is_active ? 'Активен' : 'Выкл.' }}
                    </span>
                </div>

                <p class="line-clamp-3 text-[13px] text-text-secondary">{{ $item->content }}</p>

                <div class="flex flex-wrap gap-1.5">
                    @foreach ($keywords as $keyword)
                        <span class="max-w-[130px] truncate rounded-md bg-bg-secondary px-2 py-1 text-[11px] text-text-secondary" title="{{ $keyword }}">{{ $keyword }}</span>
                    @endforeach
                </div>

                <div class="flex items-center justify-between gap-3 pt-1 text-[12px] text-text-secondary">
                    <span>Приоритет: {{ $item->priority }}</span>
                    <div class="flex items-center gap-3">
                        <button type="button" wire:click="openEdit({{ $item->id }})" class="font-medium transition hover:text-accent" title="Редактировать блок">Изменить</button>
                        <button type="button" wire:click="toggleActive({{ $item->id }})" class="font-medium transition hover:text-accent" title="{{ $item->is_active ? 'Выключить блок' : 'Включить блок' }}">{{ $item->is_active ? 'Выключить' : 'Включить' }}</button>
                        <button type="button" wire:click="deleteItem({{ $item->id }})" wire:confirm="Удалить блок «{{ $item->title }}»? Действие необратимо." class="font-medium transition hover:text-red-500" title="Удалить блок">Удалить</button>
                    </div>
                </div>
            </div>
        @empty
            <div class="px-6 py-12 text-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="mx-auto mb-3 h-8 w-8 text-text-secondary" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2m6-2a10 10 0 11-20 0 10 10 0 0120 0z" />
                </svg>
                <p class="text-sm text-text-secondary">Блоков знаний пока нет. Добавьте первый блок.</p>
            </div>
        @endforelse

        @if ($items->hasPages())
            <div class="border-t border-border-light px-4 py-3 lg:px-6">
                {{ $items->links() }}
            </div>
        @endif
    </div>

    @endif

    @if ($activeTab === 'moderation')
        <div class="space-y-4" role="tabpanel" aria-label="Правила AI-модерации">
            <div class="rounded-xl border border-border-light bg-bg-primary p-4 lg:p-6">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <h2 class="text-base font-semibold text-text-primary">AI-модератор</h2>
                        <p class="mt-1 text-sm text-text-secondary">Правила, по которым DeepSeek или другой AI-провайдер оценивает support-кейсы перед добавлением в базу.</p>
                    </div>
                    <div class="rounded-[10px] border border-border-light px-4 py-2.5 text-sm text-text-secondary">
                        Документ: <code class="text-text-primary">docs/ai-support-moderation.md</code>
                    </div>
                </div>

                <div class="mt-4 grid gap-3 sm:grid-cols-3">
                    <div class="rounded-xl bg-bg-secondary px-4 py-3" title="AI-провайдер для модерации">
                        <p class="text-xs text-text-secondary">Модератор</p>
                        <p class="mt-1 text-lg font-semibold text-text-primary">{{ $moderationInfo['provider'] }}</p>
                    </div>
                    <div class="rounded-xl bg-bg-secondary px-4 py-3" title="Модель AI-модератора">
                        <p class="text-xs text-text-secondary">Модель</p>
                        <p class="mt-1 text-lg font-semibold text-text-primary">{{ $moderationInfo['model'] }}</p>
                    </div>
                    <div class="rounded-xl bg-bg-secondary px-4 py-3" title="Версия правил модерации">
                        <p class="text-xs text-text-secondary">Версия правил</p>
                        <p class="mt-1 text-lg font-semibold text-text-primary">{{ $moderationInfo['rules_version'] }}</p>
                    </div>
                </div>
            </div>

            <div class="rounded-xl border border-border-light bg-bg-primary p-4 lg:p-6">
                <h3 class="text-sm font-semibold text-text-primary">Как это работает</h3>
                <ol class="mt-3 list-decimal space-y-2 pl-5 text-sm text-text-secondary">
                    <li>Код собирает кандидаты только внутри одного клиента или диалога.</li>
                    <li>Подряд идущие сообщения клиента объединяются в один блок.</li>
                    <li>Подряд идущие ответы оператора объединяются в один блок.</li>
                    <li>AI-модератор возвращает строгий JSON со статусом, причиной и рисками.</li>
                    <li>AI-ответчик использует только записи со статусом <span class="font-medium text-text-primary">Активен</span>.</li>
                </ol>
            </div>

            <div class="grid gap-4 lg:grid-cols-3">
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4" title="Статус доступен AI">
                    <h3 class="text-sm font-semibold text-emerald-700">Активен</h3>
                    <p class="mt-2 text-sm text-emerald-700">Чистый кейс: понятный вопрос, полезный ответ, нет мусора и сомнений.</p>
                </div>
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4" title="Статус требует ручной проверки">
                    <h3 class="text-sm font-semibold text-amber-700">Нужно проверить</h3>
                    <p class="mt-2 text-sm text-amber-700">Сомнительный кейс, возможный дубль или риск устаревших данных. AI его не использует.</p>
                </div>
                <div class="rounded-xl border border-gray-200 bg-gray-50 p-4" title="Статус выключен для AI">
                    <h3 class="text-sm font-semibold text-gray-700">Выключен</h3>
                    <p class="mt-2 text-sm text-gray-700">Явный мусор или бесполезный пример. Хранится, но не попадает в AI-контекст.</p>
                </div>
            </div>
        </div>
    @endif

    @if ($supportDrawerOpen && $selectedSupportChunk)
        <div
            wire:click="closeSupportDrawer"
            class="fixed inset-0 z-50 bg-black/40"
            aria-hidden="true"
            title="Закрыть карточку support-кейса"
        ></div>

        <section
            class="fixed inset-y-0 right-0 z-50 flex w-full flex-col border-l border-border-light bg-bg-primary shadow-2xl lg:w-1/2"
            role="dialog"
            aria-modal="true"
            aria-label="Карточка support-кейса"
        >
            <header class="sticky top-0 z-10 flex items-start justify-between gap-3 border-b border-border-light bg-bg-primary px-4 py-4 lg:px-6">
                <div class="min-w-0">
                    <p class="text-xs font-medium uppercase tracking-wide text-text-secondary">Support-кейс #{{ $selectedSupportChunk->id }}</p>
                    <h3 class="mt-1 truncate text-lg font-semibold text-text-primary">{{ $selectedSupportChunk->statusLabel() }}</h3>
                    <p class="mt-1 text-xs text-text-secondary">
                        {{ $selectedSupportChunk->first_message_at?->format('d.m.Y H:i') ?? 'Без даты начала' }} — {{ $selectedSupportChunk->last_message_at?->format('d.m.Y H:i') ?? 'без даты ответа' }}
                    </p>
                </div>
                <button type="button" wire:click="closeSupportDrawer" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-text-secondary transition hover:bg-bg-secondary hover:text-text-primary" title="Закрыть карточку support-кейса">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M18 6 6 18M6 6l12 12" />
                    </svg>
                </button>
            </header>

            <div class="flex-1 space-y-4 overflow-y-auto px-4 py-5 lg:px-6">
                <div class="grid gap-3 sm:grid-cols-3">
                    <button type="button" wire:click="setSupportChunkStatus({{ $selectedSupportChunk->id }}, 'active')" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-left text-sm font-medium text-emerald-700 transition hover:bg-emerald-100" title="Активировать support-кейс">Активен</button>
                    <button type="button" wire:click="setSupportChunkStatus({{ $selectedSupportChunk->id }}, 'review')" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-left text-sm font-medium text-amber-700 transition hover:bg-amber-100" title="Отправить support-кейс на проверку">Нужно проверить</button>
                    <button type="button" wire:click="setSupportChunkStatus({{ $selectedSupportChunk->id }}, 'disabled')" class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-left text-sm font-medium text-gray-700 transition hover:bg-gray-100" title="Выключить support-кейс">Выключен</button>
                </div>

                <div class="grid gap-4 lg:grid-cols-2">
                    <div class="rounded-xl border border-border-light bg-bg-secondary p-4" title="Клиентский текст: оригинал и русский смысл">
                        <div class="flex items-start justify-between gap-2">
                            <p class="text-xs font-semibold uppercase tracking-wide text-text-secondary">Клиент</p>
                            <button type="button" wire:click="translateSupportChunk('question')" class="rounded-lg px-2 py-1 text-xs font-medium text-blue-700 transition hover:bg-blue-50" title="Перевести клиентский текст на русский">Перевести на русский</button>
                        </div>
                        <p class="mt-3 text-xs font-medium text-text-secondary">Оригинал</p>
                        <div class="mt-1 whitespace-pre-wrap text-sm text-text-primary">{{ $selectedSupportChunk->originalQuestion() }}</div>
                        <x-admin.form-field label="RU canonical" for="support_question_ru">
                            <textarea id="support_question_ru" wire:model.defer="supportForm.question_ru" rows="5" class="w-full rounded-[10px] border border-border-light bg-bg-primary px-3 py-2.5 text-sm text-text-primary outline-none transition focus:border-accent focus:ring-2 focus:ring-accent/20" title="Отредактируйте русский смысл вопроса клиента"></textarea>
                        </x-admin.form-field>
                        <p class="text-xs text-text-secondary" title="Статус RU canonical клиента">Статус: {{ $selectedSupportChunk->translationStatusLabel($selectedSupportChunk->question_translation_status) }} @if($selectedSupportChunk->question_translation_error) · {{ $selectedSupportChunk->question_translation_error }} @endif</p>
                    </div>

                    <div class="rounded-xl border border-border-light bg-bg-secondary p-4" title="Операторский текст: оригинал и русский смысл">
                        <div class="flex items-start justify-between gap-2">
                            <p class="text-xs font-semibold uppercase tracking-wide text-text-secondary">Оператор</p>
                            <button type="button" wire:click="translateSupportChunk('answer')" class="rounded-lg px-2 py-1 text-xs font-medium text-blue-700 transition hover:bg-blue-50" title="Перевести операторский текст на русский">Перевести на русский</button>
                        </div>
                        <p class="mt-3 text-xs font-medium text-text-secondary">Оригинал</p>
                        <div class="mt-1 whitespace-pre-wrap text-sm text-text-primary">{{ $selectedSupportChunk->originalAnswer() }}</div>
                        <x-admin.form-field label="RU canonical" for="support_answer_ru">
                            <textarea id="support_answer_ru" wire:model.defer="supportForm.answer_ru" rows="5" class="w-full rounded-[10px] border border-border-light bg-bg-primary px-3 py-2.5 text-sm text-text-primary outline-none transition focus:border-accent focus:ring-2 focus:ring-accent/20" title="Отредактируйте русский смысл ответа оператора"></textarea>
                        </x-admin.form-field>
                        <p class="text-xs text-text-secondary" title="Статус RU canonical оператора">Статус: {{ $selectedSupportChunk->translationStatusLabel($selectedSupportChunk->answer_translation_status) }} @if($selectedSupportChunk->answer_translation_error) · {{ $selectedSupportChunk->answer_translation_error }} @endif</p>
                    </div>
                </div>

                <div class="rounded-xl border border-border-light p-4" title="Инструкция для AI по использованию этого кейса">
                    <x-admin.form-field label="Инструкция для AI" for="support_ai_instruction" hint="Объясните модели, что уточнять, что нельзя обещать и где брать информацию">
                        <textarea id="support_ai_instruction" wire:model.defer="supportForm.ai_instruction" rows="4" class="w-full rounded-[10px] border border-border-light bg-bg-primary px-3 py-2.5 text-sm text-text-primary outline-none transition focus:border-accent focus:ring-2 focus:ring-accent/20" title="Введите инструкцию для AI по этому support-кейсу"></textarea>
                    </x-admin.form-field>
                    @if (! $selectedSupportChunk->ai_instruction)
                        <p class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-700" title="Инструкция AI пока не заполнена">Нет инструкции: кейс не блокируется, но AI получит меньше правил.</p>
                    @endif
                </div>

                <div class="rounded-xl border border-blue-200 bg-blue-50 p-4" title="Предпросмотр контекста для AI">
                    <p class="text-xs font-semibold uppercase tracking-wide text-blue-700">Что увидит AI</p>
                    <pre class="mt-2 whitespace-pre-wrap text-xs text-blue-900">Support-кейс:
Клиент RU: {{ $supportForm['question_ru'] ?: $selectedSupportChunk->canonicalQuestion() }}
Оператор RU: {{ $supportForm['answer_ru'] ?: $selectedSupportChunk->canonicalAnswer() }}
Клиент оригинал: {{ $selectedSupportChunk->originalQuestion() }}
Оператор оригинал: {{ $selectedSupportChunk->originalAnswer() }}
Инструкция AI: {{ $supportForm['ai_instruction'] ?: 'не указана' }}</pre>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="rounded-xl border border-border-light p-4" title="Источник support-кейса">
                        <p class="text-xs text-text-secondary">Source hash</p>
                        <code class="mt-1 block break-all text-xs text-text-primary">{{ $selectedSupportChunk->source_hash }}</code>
                    </div>
                    <div class="rounded-xl border border-border-light p-4" title="Группа дублей">
                        <p class="text-xs text-text-secondary">Дубль?</p>
                        <p class="mt-1 text-sm text-text-primary">{{ $selectedSupportChunk->duplicate_group_key ?: 'Нет данных' }}</p>
                    </div>
                </div>

                <div class="rounded-xl border border-border-light p-4" title="Причина статуса или проверки">
                    <p class="text-xs text-text-secondary">Причина модерации</p>
                    <p class="mt-1 text-sm text-text-primary">{{ $selectedSupportChunk->moderation_reason ?: 'Причина пока не указана.' }}</p>
                </div>
            </div>

            <footer class="sticky bottom-0 flex flex-col-reverse gap-2 border-t border-border-light bg-bg-primary px-4 py-4 sm:flex-row sm:items-center sm:justify-between lg:px-6">
                <button type="button" wire:click="deleteSupportChunk({{ $selectedSupportChunk->id }})" wire:confirm="Удалить support-кейс? Действие необратимо." class="inline-flex items-center justify-center rounded-[10px] px-4 py-2.5 text-sm font-medium text-red-600 transition hover:bg-red-50" title="Удалить support-кейс физически">Удалить</button>
                <div class="flex flex-col gap-2 sm:flex-row sm:justify-end">
                    <button type="button" wire:click="translateSupportChunk('all')" class="inline-flex items-center justify-center rounded-[10px] border border-blue-200 px-4 py-2.5 text-sm font-medium text-blue-700 transition hover:bg-blue-50" title="Поставить клиентский и операторский текст в очередь RU canonical">Перевести оба</button>
                    <button type="button" wire:click="saveSupportCanonicalFields" class="inline-flex items-center justify-center rounded-[10px] bg-accent px-4 py-2.5 text-sm font-medium text-white transition hover:bg-blue-600" title="Сохранить RU canonical и инструкцию AI">Сохранить</button>
                    <button type="button" wire:click="closeSupportDrawer" class="inline-flex items-center justify-center rounded-[10px] border border-border-light px-4 py-2.5 text-sm font-medium text-text-primary transition hover:bg-bg-secondary" title="Закрыть карточку support-кейса">Закрыть</button>
                </div>
            </footer>
        </section>
    @endif

    @if ($fillAiModalOpen)
        <div
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4 py-6"
            role="dialog"
            aria-modal="true"
            aria-label="Пополнить базу AI"
            title="Модальное окно пополнения базы AI"
        >
            <div class="w-full max-w-2xl overflow-hidden rounded-2xl border border-border-light bg-bg-primary shadow-2xl">
                <header class="flex items-start justify-between gap-3 border-b border-border-light px-5 py-4">
                    <div>
                        <h3 class="text-lg font-semibold text-text-primary">Пополнить базу AI</h3>
                        <p class="mt-1 text-sm text-text-secondary">Система возьмёт текущие диалоги из базы, соберёт кандидаты и отправит их на AI-модерацию. Старые кейсы не перезаписываются.</p>
                    </div>
                    <button type="button" wire:click="closeFillAiModal" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-text-secondary transition hover:bg-bg-secondary hover:text-text-primary" title="Закрыть окно пополнения базы AI">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M18 6 6 18M6 6l12 12" />
                        </svg>
                    </button>
                </header>

                <div class="space-y-4 px-5 py-4">
                    <x-admin.form-field label="Сколько последних диалогов проверить" for="fill_ai_limit">
                        <input
                            id="fill_ai_limit"
                            type="number"
                            min="1"
                            max="1000"
                            wire:model.live.debounce.500ms="fillAiLimit"
                            wire:change="refreshFillAiPreview"
                            class="w-full rounded-[10px] border border-border-light bg-bg-primary px-3 py-2.5 text-sm text-text-primary outline-none transition focus:border-accent focus:ring-2 focus:ring-accent/20"
                            title="Укажите количество последних диалогов для пополнения базы AI"
                        >
                    </x-admin.form-field>

                    <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800" title="Что произойдёт при пополнении базы AI">
                        <p class="font-medium">Что будет сделано:</p>
                        <ul class="mt-2 list-disc space-y-1 pl-5">
                            <li>вопросы и ответы будут сгруппированы только внутри одного клиента;</li>
                            <li>новые кейсы сначала получат статус «Нужно проверить»;</li>
                            <li>AI-модератор попробует активировать чистые кейсы или выключить мусор;</li>
                            <li>физическое удаление автоматически не выполняется.</li>
                        </ul>
                    </div>

                    @if ($fillAiPreview)
                        <div class="grid gap-3 sm:grid-cols-3">
                            <div class="rounded-xl bg-bg-secondary px-4 py-3" title="Сколько диалогов будет просмотрено">
                                <p class="text-xs text-text-secondary">Диалоги</p>
                                <p class="mt-1 text-xl font-semibold text-text-primary">{{ $fillAiPreview['dialogs_count'] }}</p>
                            </div>
                            <div class="rounded-xl bg-bg-secondary px-4 py-3" title="Сколько сообщений будет прочитано">
                                <p class="text-xs text-text-secondary">Сообщения</p>
                                <p class="mt-1 text-xl font-semibold text-text-primary">{{ $fillAiPreview['messages_count'] }}</p>
                            </div>
                            <div class="rounded-xl bg-bg-secondary px-4 py-3" title="Сколько кандидатов будет собрано">
                                <p class="text-xs text-text-secondary">Кандидаты</p>
                                <p class="mt-1 text-xl font-semibold text-text-primary">{{ $fillAiPreview['chunks_count'] }}</p>
                            </div>
                        </div>
                    @endif
                </div>

                <footer class="flex flex-col-reverse gap-2 border-t border-border-light px-5 py-4 sm:flex-row sm:justify-end">
                    <button type="button" wire:click="closeFillAiModal" class="inline-flex items-center justify-center rounded-[10px] border border-border-light px-4 py-2.5 text-sm font-medium text-text-primary transition hover:bg-bg-secondary" title="Отменить пополнение базы AI">Отмена</button>
                    <button type="button" wire:click="refreshFillAiPreview" wire:loading.attr="disabled" wire:target="refreshFillAiPreview" class="inline-flex items-center justify-center rounded-[10px] border border-border-light px-4 py-2.5 text-sm font-medium text-text-primary transition hover:bg-bg-secondary disabled:opacity-60" title="Пересчитать кандидаты перед пополнением">Пересчитать</button>
                    <button type="button" wire:click="fillAiFromCurrentDialogs" wire:loading.attr="disabled" wire:target="fillAiFromCurrentDialogs" class="inline-flex items-center justify-center rounded-[10px] bg-accent px-4 py-2.5 text-sm font-medium text-white transition hover:bg-accent/90 disabled:opacity-60" title="Запустить пополнение базы AI">
                        <span wire:loading.remove wire:target="fillAiFromCurrentDialogs">Запустить</span>
                        <span wire:loading wire:target="fillAiFromCurrentDialogs">Пополняю…</span>
                    </button>
                </footer>
            </div>
        </div>
    @endif

    @if ($archiveImportModalOpen)
        <div
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4 py-6"
            role="dialog"
            aria-modal="true"
            aria-label="Пополнить базу AI из архива"
            title="Модальное окно импорта архива"
        >
            <div class="w-full max-w-2xl overflow-hidden rounded-2xl border border-border-light bg-bg-primary shadow-2xl">
                <header class="flex items-start justify-between gap-3 border-b border-border-light px-5 py-4">
                    <div>
                        <h3 class="text-lg font-semibold text-text-primary">Пополнить базу AI из архива</h3>
                        <p class="mt-1 text-sm text-text-secondary">Укажите папку Telegram HTML export. Система прочитает архив, создаст только новые кейсы и отправит их на AI-модерацию.</p>
                    </div>
                    <button type="button" wire:click="closeArchiveImportModal" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-text-secondary transition hover:bg-bg-secondary hover:text-text-primary" title="Закрыть окно импорта архива">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M18 6 6 18M6 6l12 12" />
                        </svg>
                    </button>
                </header>

                <div class="space-y-4 px-5 py-4">
                    <x-admin.form-field label="Папка архива" for="archive_import_path">
                        <input
                            id="archive_import_path"
                            type="text"
                            wire:model.live.debounce.500ms="archiveImportPath"
                            class="w-full rounded-[10px] border border-border-light bg-bg-primary px-3 py-2.5 text-sm text-text-primary outline-none transition focus:border-accent focus:ring-2 focus:ring-accent/20"
                            placeholder="C:\Users\umidt\Downloads\Архив support"
                            title="Укажите путь к папке Telegram HTML архива"
                        >
                    </x-admin.form-field>

                    <div class="rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800" title="Что произойдёт при импорте архива">
                        Архивный импорт не смешивается с текущими диалогами: новые кейсы получают статус «Нужно проверить», затем проходят AI-модерацию. Уже существующие кейсы не перезаписываются.
                    </div>

                    @if ($archiveImportPreview)
                        <div class="grid gap-3 sm:grid-cols-3">
                            <div class="rounded-xl bg-bg-secondary px-4 py-3" title="Сколько файлов архива найдено">
                                <p class="text-xs text-text-secondary">Файлы</p>
                                <p class="mt-1 text-xl font-semibold text-text-primary">{{ count($archiveImportPreview['files']) }}</p>
                            </div>
                            <div class="rounded-xl bg-bg-secondary px-4 py-3" title="Сколько сообщений найдено в архиве">
                                <p class="text-xs text-text-secondary">Сообщения</p>
                                <p class="mt-1 text-xl font-semibold text-text-primary">{{ $archiveImportPreview['messages_count'] }}</p>
                            </div>
                            <div class="rounded-xl bg-bg-secondary px-4 py-3" title="Сколько кандидатов будет собрано из архива">
                                <p class="text-xs text-text-secondary">Кандидаты</p>
                                <p class="mt-1 text-xl font-semibold text-text-primary">{{ $archiveImportPreview['chunks_count'] }}</p>
                            </div>
                        </div>
                    @endif
                </div>

                <footer class="flex flex-col-reverse gap-2 border-t border-border-light px-5 py-4 sm:flex-row sm:justify-end">
                    <button type="button" wire:click="closeArchiveImportModal" class="inline-flex items-center justify-center rounded-[10px] border border-border-light px-4 py-2.5 text-sm font-medium text-text-primary transition hover:bg-bg-secondary" title="Отменить импорт архива">Отмена</button>
                    <button type="button" wire:click="refreshArchiveImportPreview" wire:loading.attr="disabled" wire:target="refreshArchiveImportPreview" class="inline-flex items-center justify-center rounded-[10px] border border-border-light px-4 py-2.5 text-sm font-medium text-text-primary transition hover:bg-bg-secondary disabled:opacity-60" title="Пересчитать кандидаты из архива">Пересчитать</button>
                    <button type="button" wire:click="fillAiFromArchive" wire:loading.attr="disabled" wire:target="fillAiFromArchive" class="inline-flex items-center justify-center rounded-[10px] bg-accent px-4 py-2.5 text-sm font-medium text-white transition hover:bg-accent/90 disabled:opacity-60" title="Запустить импорт архива в базу AI">
                        <span wire:loading.remove wire:target="fillAiFromArchive">Запустить</span>
                        <span wire:loading wire:target="fillAiFromArchive">Импортирую…</span>
                    </button>
                </footer>
            </div>
        </div>
    @endif

    {{-- Drawer backdrop --}}
    <div
        x-show="drawerOpen"
        x-cloak
        x-transition.opacity
        wire:click="closeDrawer"
        class="fixed inset-0 z-50 bg-black/40"
        aria-hidden="true"
        title="Закрыть карточку"
    ></div>

    {{-- Drawer --}}
    <section
        x-show="drawerOpen"
        x-cloak
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="translate-x-full"
        x-transition:enter-end="translate-x-0"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="translate-x-0"
        x-transition:leave-end="translate-x-full"
        class="fixed inset-y-0 right-0 z-50 flex w-full flex-col border-l border-border-light bg-bg-primary shadow-2xl lg:w-1/2"
        role="dialog"
        aria-modal="true"
        aria-label="Карточка блока знаний AI"
    >
        <header class="sticky top-0 z-10 flex items-start justify-between gap-3 border-b border-border-light bg-bg-primary px-4 py-4 lg:px-6">
            <div class="min-w-0">
                <p class="text-xs font-medium uppercase tracking-wide text-text-secondary">
                    {{ $drawerMode === 'create' ? 'Создание' : ($drawerMode === 'edit' ? 'Редактирование' : 'Просмотр') }}
                </p>
                <h3 class="mt-1 truncate text-lg font-semibold text-text-primary">
                    {{ $form['title'] !== '' ? $form['title'] : 'Новый блок знаний' }}
                </h3>
            </div>
            <button type="button" wire:click="closeDrawer" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-text-secondary transition hover:bg-bg-secondary hover:text-text-primary" title="Закрыть карточку">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M18 6 6 18M6 6l12 12" />
                </svg>
            </button>
        </header>

        <div class="flex-1 space-y-4 overflow-y-auto px-4 py-5 lg:px-6">
            @php($readonly = $drawerMode === 'view')

            <x-admin.form-field label="Название" for="knowledge_title" :error="$errors->first('form.title')" required>
                <input id="knowledge_title" type="text" wire:model.defer="form.title" @disabled($readonly) class="w-full rounded-[10px] border border-border-light bg-bg-primary px-3 py-2.5 text-sm text-text-primary outline-none transition disabled:opacity-70 focus:border-accent focus:ring-2 focus:ring-accent/20" title="Введите название блока знаний">
            </x-admin.form-field>

            <x-admin.form-field label="Slug" for="knowledge_slug" hint="Латиница, цифры, дефис и подчёркивание" :error="$errors->first('form.slug')" required>
                <input id="knowledge_slug" type="text" wire:model.defer="form.slug" @disabled($readonly) class="w-full rounded-[10px] border border-border-light bg-bg-primary px-3 py-2.5 text-sm text-text-primary outline-none transition disabled:opacity-70 focus:border-accent focus:ring-2 focus:ring-accent/20" title="Введите технический slug">
            </x-admin.form-field>

            <x-admin.form-field label="Текст знания" for="knowledge_content" :error="$errors->first('form.content')" required>
                <textarea id="knowledge_content" wire:model.defer="form.content" rows="10" @disabled($readonly) class="min-h-[220px] w-full rounded-[10px] border border-border-light bg-bg-primary px-3 py-2.5 text-sm text-text-primary outline-none transition disabled:opacity-70 focus:border-accent focus:ring-2 focus:ring-accent/20" title="Введите полный текст блока знаний"></textarea>
            </x-admin.form-field>

            <x-admin.form-field label="Ключевые слова" for="knowledge_keywords" hint="Через запятую: brospace, цена, подписка" :error="$errors->first('form.keywords')">
                <textarea id="knowledge_keywords" wire:model.defer="form.keywords" rows="3" @disabled($readonly) class="w-full rounded-[10px] border border-border-light bg-bg-primary px-3 py-2.5 text-sm text-text-primary outline-none transition disabled:opacity-70 focus:border-accent focus:ring-2 focus:ring-accent/20" title="Введите ключевые слова через запятую"></textarea>
            </x-admin.form-field>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-admin.form-field label="Приоритет" for="knowledge_priority" :error="$errors->first('form.priority')" required>
                    <input id="knowledge_priority" type="number" min="0" max="65535" wire:model.defer="form.priority" @disabled($readonly) class="w-full rounded-[10px] border border-border-light bg-bg-primary px-3 py-2.5 text-sm text-text-primary outline-none transition disabled:opacity-70 focus:border-accent focus:ring-2 focus:ring-accent/20" title="Введите приоритет сортировки">
                </x-admin.form-field>

                <x-admin.form-field label="Активность" for="knowledge_active">
                    <label class="flex min-h-[42px] items-center gap-3 rounded-[10px] border border-border-light px-3 py-2.5" title="Включить или выключить блок знаний">
                        <input id="knowledge_active" type="checkbox" wire:model.defer="form.is_active" @disabled($readonly) class="h-4 w-4 rounded border-border-light text-accent focus:ring-accent">
                        <span class="text-sm text-text-primary">Использовать в AI-поиске</span>
                    </label>
                </x-admin.form-field>
            </div>
        </div>

        <footer class="sticky bottom-0 flex flex-col-reverse gap-2 border-t border-border-light bg-bg-primary px-4 py-4 sm:flex-row sm:items-center sm:justify-between lg:px-6">
            <div>
                @if ($editingId)
                    <button type="button" wire:click="deleteItem({{ $editingId }})" wire:confirm="Удалить блок «{{ $form['title'] }}»? Действие необратимо." class="inline-flex items-center justify-center rounded-[10px] px-4 py-2.5 text-sm font-medium text-red-600 transition hover:bg-red-50" title="Удалить текущий блок">
                        Удалить
                    </button>
                @endif
            </div>

            <div class="flex flex-col gap-2 sm:flex-row sm:justify-end">
                <button type="button" wire:click="closeDrawer" class="inline-flex items-center justify-center rounded-[10px] border border-border-light px-4 py-2.5 text-sm font-medium text-text-primary transition hover:bg-bg-secondary" title="Отменить и закрыть">
                    Отмена
                </button>

                @if ($drawerMode === 'view')
                    <button type="button" wire:click="switchToEdit" class="inline-flex items-center justify-center rounded-[10px] bg-accent px-4 py-2.5 text-sm font-medium text-white transition hover:bg-blue-600" title="Перейти к редактированию">
                        Редактировать
                    </button>
                @else
                    <button type="button" wire:click="save" wire:loading.attr="disabled" class="inline-flex items-center justify-center rounded-[10px] bg-accent px-4 py-2.5 text-sm font-medium text-white transition hover:bg-blue-600 disabled:opacity-60" title="Сохранить блок знаний">
                        <span wire:loading.remove wire:target="save">Сохранить</span>
                        <span wire:loading wire:target="save">Сохраняю…</span>
                    </button>
                @endif
            </div>
        </footer>
    </section>
</div>

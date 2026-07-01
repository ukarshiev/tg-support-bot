<div class="p-4 lg:p-8" x-data="{ drawerOpen: @entangle('drawerOpen') }" x-on:keydown.escape.window="$wire.closeDrawer()">
    {{-- Header --}}
    <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div class="min-w-0">
            <h1 class="text-2xl font-bold text-text-primary">База знаний AI</h1>
            <p class="mt-1 text-sm text-text-secondary">Управляйте блоками знаний, которые AI использует вместо длинного системного промпта</p>
        </div>

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

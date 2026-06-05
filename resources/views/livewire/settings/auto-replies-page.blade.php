<div class="p-6 lg:p-8">

    {{-- ── Page header (consistent with other settings pages) ─────────────────────── --}}
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-text-primary">Автоответы</h1>
        <p class="mt-1 text-sm text-text-secondary">Настройте автоматические ответы на частые вопросы</p>
    </div>

    {{-- ── Top row: rules counter + add button ────────────────────────────────────── --}}
    <div class="mb-4 flex items-center justify-between gap-3">
        <span class="text-sm text-text-secondary">{{ $this->rulesCountLabel(count($rules)) }}</span>

        <a href="{{ route('admin.settings.auto-replies.create') }}"
           class="inline-flex shrink-0 items-center justify-center rounded-[10px] bg-accent px-5 py-2.5 text-sm font-medium text-white transition hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="mr-1.5 h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
            </svg>
            Добавить правило
        </a>
    </div>

    {{-- ── Rules table ────────────────────────────────────────────────────────────── --}}
    <div class="rounded-xl border border-border-light bg-bg-primary">

        {{-- Column headers — desktop only --}}
        <div class="hidden grid-cols-[180px_1fr_80px] items-center bg-[#FAFAFA] px-6 py-3 text-[12px] font-medium text-text-secondary lg:grid">
            <span>Триггер</span>
            <span>Ответ</span>
            <span class="text-center">Действия</span>
        </div>
        <div class="hidden border-t border-border-light lg:block"></div>

        @forelse ($rules as $rule)

            {{-- Divider (not before first row) --}}
            @if (! $loop->first)
                <div class="border-t border-border-light"></div>
            @endif

            {{-- Desktop: grid row --}}
            <div class="hidden grid-cols-[180px_1fr_80px] items-center px-6 py-3.5 lg:grid">

                {{-- Trigger --}}
                <div class="flex min-w-0 items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0 text-accent" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z" />
                    </svg>
                    <span class="truncate text-sm font-medium text-text-primary">{{ $rule->trigger }}</span>
                </div>

                {{-- Response --}}
                <p class="min-w-0 truncate pr-6 text-[13px] text-text-secondary">{{ $rule->response }}</p>

                {{-- Actions --}}
                <div class="flex items-center justify-center gap-3">
                    <a href="{{ route('admin.settings.auto-replies.edit', ['rule' => $rule->id]) }}"
                       class="flex h-8 w-8 items-center justify-center rounded-lg text-text-secondary transition hover:bg-bg-secondary hover:text-accent" title="Редактировать">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 3a2.828 2.828 0 114 4L7.5 20.5 2 22l1.5-5.5L17 3z" />
                        </svg>
                    </a>
                    <button type="button"
                            wire:click="deleteRule({{ $rule->id }})"
                            wire:confirm="Удалить правило «{{ $rule->trigger }}»?"
                            class="flex h-8 w-8 items-center justify-center rounded-lg text-text-secondary transition hover:bg-bg-secondary hover:text-red-500" title="Удалить">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                        </svg>
                    </button>
                </div>
            </div>

            {{-- Mobile: stacked card --}}
            <div class="flex flex-col gap-2 px-4 py-3.5 lg:hidden">
                <div class="flex min-w-0 items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0 text-accent" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z" />
                    </svg>
                    <span class="truncate text-sm font-medium text-text-primary">{{ $rule->trigger }}</span>
                </div>

                <p class="text-[13px] text-text-secondary">{{ $rule->response }}</p>

                <div class="flex items-center gap-4 pt-1">
                    <a href="{{ route('admin.settings.auto-replies.edit', ['rule' => $rule->id]) }}"
                       class="inline-flex items-center gap-1 text-[12px] font-medium text-text-secondary transition hover:text-accent">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 3a2.828 2.828 0 114 4L7.5 20.5 2 22l1.5-5.5L17 3z" />
                        </svg>
                        Изменить
                    </a>
                    <button type="button"
                            wire:click="deleteRule({{ $rule->id }})"
                            wire:confirm="Удалить правило «{{ $rule->trigger }}»?"
                            class="inline-flex items-center gap-1 text-[12px] font-medium text-text-secondary transition hover:text-red-500">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                        </svg>
                        Удалить
                    </button>
                </div>
            </div>

        @empty
            {{-- Empty state --}}
            <div class="px-6 py-12 text-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="mx-auto mb-3 h-8 w-8 text-text-secondary" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z" />
                </svg>
                <p class="text-sm text-text-secondary">Правил пока нет. Добавьте первое правило.</p>
            </div>
        @endforelse
    </div>

</div>

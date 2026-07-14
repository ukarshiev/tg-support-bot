<div class="p-6 lg:p-8">
    @include('livewire.settings.partials.language-tabs', ['active' => 'queue'])

    <div class="mb-5 grid gap-3 rounded-xl border border-border-light bg-bg-primary p-4 lg:grid-cols-[1fr_180px_180px_180px_auto] lg:items-end">
        <label class="block" title="Найти задачу по объекту, провайдеру или ошибке">
            <span class="mb-1 block text-xs font-medium text-text-secondary">Поиск</span>
            <input type="search" wire:model.live.debounce.400ms="search" title="Введите текст для поиска по очереди"
                   placeholder="Например: приветствие, yandex, ошибка"
                   class="h-11 w-full rounded-lg border border-border-light bg-bg-primary px-3 text-sm text-text-primary placeholder-text-secondary">
        </label>

        <label class="block" title="Отфильтровать задачи по статусу">
            <span class="mb-1 block text-xs font-medium text-text-secondary">Статус</span>
            <select wire:model.live="statusFilter" title="Выберите статус задачи"
                    class="h-11 w-full rounded-lg border border-border-light bg-bg-primary px-3 text-sm text-text-primary">
                <option value="">Все статусы</option>
                @foreach($statusLabels as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </label>

        <label class="block" title="Отфильтровать задачи по типу перевода">
            <span class="mb-1 block text-xs font-medium text-text-secondary">Тип</span>
            <select wire:model.live="typeFilter" title="Выберите тип задачи"
                    class="h-11 w-full rounded-lg border border-border-light bg-bg-primary px-3 text-sm text-text-primary">
                <option value="">Все типы</option>
                @foreach($typeLabels as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </label>

        <label class="block" title="Отфильтровать задачи по целевому языку">
            <span class="mb-1 block text-xs font-medium text-text-secondary">Язык</span>
            <select wire:model.live="localeFilter" title="Выберите целевой язык"
                    class="h-11 w-full rounded-lg border border-border-light bg-bg-primary px-3 text-sm text-text-primary">
                <option value="">Все языки</option>
                @foreach($languages as $code => $language)
                    @if($code !== 'ru')
                        <option value="{{ $code }}">{{ $language['native'] }} ({{ $code }})</option>
                    @endif
                @endforeach
            </select>
        </label>

        <button type="button" wire:click="resetFilters" title="Сбросить фильтры очереди"
                class="h-11 rounded-lg border border-border-light px-4 text-sm font-semibold text-text-primary transition hover:bg-bg-secondary">
            Сбросить
        </button>
    </div>

    <div class="overflow-hidden rounded-xl border border-border-light bg-bg-primary">
        <div class="hidden grid-cols-[150px_130px_1.4fr_95px_110px_95px_90px_1fr_120px] gap-3 border-b border-border-light px-4 py-3 text-xs font-semibold uppercase tracking-wide text-text-secondary xl:grid">
            <span>Поставлена</span>
            <span>Тип</span>
            <span>Что переводится</span>
            <span>Язык</span>
            <span>Статус</span>
            <span>Провайдер</span>
            <span>Попытки</span>
            <span>Ошибка</span>
            <span>Действие</span>
        </div>

        <div class="divide-y divide-border-light">
            @forelse($jobs as $job)
                @php($subjectUrl = $this->openSubjectUrl($job))
                <div class="grid gap-3 px-4 py-4 text-sm xl:grid-cols-[150px_130px_1.4fr_95px_110px_95px_90px_1fr_120px] xl:items-center">
                    <div>
                        <div class="font-medium text-text-primary">{{ optional($job->queued_at)->format('d.m.Y H:i') ?? '—' }}</div>
                        <div class="text-xs text-text-secondary">
                            @if($job->started_at) старт {{ $job->started_at->format('H:i') }} @endif
                            @if($job->finished_at) · конец {{ $job->finished_at->format('H:i') }} @endif
                        </div>
                    </div>
                    <div class="text-text-primary">{{ $job->typeLabel() }}</div>
                    <div class="min-w-0">
                        <div class="truncate font-medium text-text-primary" title="{{ $job->subject_label }}">{{ $job->subject_label ?? '—' }}</div>
                        <div class="truncate text-xs text-text-secondary" title="{{ $job->meta['source_preview'] ?? '' }}">{{ $job->meta['source_preview'] ?? '' }}</div>
                    </div>
                    <div class="text-text-primary">{{ $job->source_locale ?? '—' }} → {{ $job->target_locale ?? '—' }}</div>
                    <div>
                        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold
                            {{ $job->status === 'done' ? 'bg-green-500/10 text-green-300' : '' }}
                            {{ $job->status === 'failed' ? 'bg-red-500/10 text-red-300' : '' }}
                            {{ $job->status === 'running' ? 'bg-blue-500/10 text-blue-300' : '' }}
                            {{ $job->status === 'queued' ? 'bg-yellow-500/10 text-yellow-300' : '' }}
                            {{ $job->status === 'skipped' ? 'bg-slate-500/10 text-text-secondary' : '' }}">
                            {{ $job->statusLabel() }}
                        </span>
                    </div>
                    <div class="text-text-primary">{{ $job->provider ? ucfirst($job->provider) : '—' }}</div>
                    <div class="text-text-primary">{{ $job->attempts }} / 3</div>
                    <div class="min-w-0 truncate text-xs text-red-300" title="{{ $job->error_message }}">{{ $job->error_message ?? '—' }}</div>
                    <div>
                        @if($subjectUrl)
                            <a href="{{ $subjectUrl }}" title="Открыть связанный автоответ"
                               class="inline-flex rounded-lg border border-border-light px-3 py-2 text-xs font-semibold text-text-primary transition hover:bg-bg-secondary">
                                Открыть
                            </a>
                        @else
                            <span class="text-xs text-text-secondary">Нет ссылки</span>
                        @endif
                    </div>
                </div>
            @empty
                <div class="px-4 py-10 text-center text-sm text-text-secondary">
                    Очередь переводов пока пустая.
                </div>
            @endforelse
        </div>
    </div>

    <div class="mt-4">
        {{ $jobs->links() }}
    </div>
</div>

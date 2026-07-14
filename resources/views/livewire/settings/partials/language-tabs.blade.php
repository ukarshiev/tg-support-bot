@props(['active' => 'languages'])

<div class="mb-6">
    <h1 class="text-2xl font-bold text-text-primary">Языки</h1>
    <p class="mt-1 text-sm text-text-secondary">Управление языками бота и провайдерами машинного перевода</p>
</div>

<div class="mb-5 flex flex-wrap gap-2">
    <a href="{{ route('admin.settings.language') }}" title="Открыть список языков"
       class="rounded-lg px-4 py-2 text-sm font-medium {{ $active === 'languages' ? 'bg-accent text-white' : 'bg-bg-primary text-text-secondary border border-border-light' }}">
        Языки
    </a>
    <a href="{{ route('admin.settings.language', ['tab' => 'providers']) }}" title="Открыть провайдеры перевода"
       class="rounded-lg px-4 py-2 text-sm font-medium {{ $active === 'providers' ? 'bg-accent text-white' : 'bg-bg-primary text-text-secondary border border-border-light' }}">
        Провайдеры перевода
    </a>
    <a href="{{ route('admin.settings.language.translate-queue') }}" title="Открыть очередь задач перевода"
       class="rounded-lg px-4 py-2 text-sm font-medium {{ $active === 'queue' ? 'bg-accent text-white' : 'bg-bg-primary text-text-secondary border border-border-light' }}">
        Очередь переводов
    </a>
</div>

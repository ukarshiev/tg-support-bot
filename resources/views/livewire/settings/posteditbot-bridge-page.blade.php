<div class="mx-auto max-w-3xl space-y-6">
    <div>
        <h1 class="text-2xl font-bold text-text-primary">PostEditBot Bridge</h1>
        <p class="mt-1 text-sm text-text-secondary">
            Связь с PostEditBot для карточки клиента и AI-контекста. Прямой доступ к БД запрещён.
        </p>
    </div>

    @if($saved)
        <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
            Настройки сохранены. Если меняли URL или токен — перезапустите контейнеры поддержки.
        </div>
    @endif

    <form wire:submit.prevent="save" class="space-y-4 rounded-2xl border border-border-light bg-bg-primary p-5">
        <label class="flex items-center gap-3 text-sm text-text-primary" title="Включить интеграцию с PostEditBot">
            <input type="checkbox" wire:model="enabled" class="h-4 w-4 rounded border-border-light" title="Включить PostEditBot Bridge">
            <span>Включить PostEditBot Bridge</span>
        </label>

        <div>
            <label class="mb-1 block text-sm font-medium text-text-primary">URL PostEditBot API</label>
            <input
                type="url"
                wire:model.defer="api_url"
                class="w-full rounded-lg border border-border-light bg-bg-input px-3 py-2 text-sm"
                placeholder="http://post-edit-bot:55556"
                title="Введите URL API PostEditBot"
            >
            @error('api_url') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="mb-1 block text-sm font-medium text-text-primary">Bridge-token</label>
            <input
                type="password"
                wire:model.defer="token"
                class="w-full rounded-lg border border-border-light bg-bg-input px-3 py-2 text-sm"
                placeholder="Заполните только для установки или замены"
                title="Введите bridge-token"
            >
            <p class="mt-1 text-xs text-text-secondary">Токен хранится как секрет в настройках Laravel.</p>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <div>
                <label class="mb-1 block text-sm font-medium text-text-primary">Timeout, мс</label>
                <input type="number" min="1000" max="30000" wire:model.defer="timeout_ms" class="w-full rounded-lg border border-border-light bg-bg-input px-3 py-2 text-sm" title="Введите timeout запроса">
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-text-primary">Кэш, секунд</label>
                <input type="number" min="10" max="3600" wire:model.defer="cache_ttl_seconds" class="w-full rounded-lg border border-border-light bg-bg-input px-3 py-2 text-sm" title="Введите время кэша карточки">
            </div>
        </div>

        <div>
            <label class="mb-1 block text-sm font-medium text-text-primary">AI-режим</label>
            <select wire:model.defer="ai_mode" class="w-full rounded-lg border border-border-light bg-bg-input px-3 py-2 text-sm" title="Выберите режим AI">
                <option value="draft">Только черновики</option>
                <option value="hybrid">Гибрид</option>
                <option value="auto">Автоответы</option>
            </select>
        </div>

        <label class="flex items-center gap-3 text-sm text-text-primary" title="Показывать карточку клиента справа в чате">
            <input type="checkbox" wire:model="show_client_card" class="h-4 w-4 rounded border-border-light" title="Показывать карточку клиента">
            <span>Показывать карточку клиента в чате</span>
        </label>

        <button type="submit" class="rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-600" title="Сохранить настройки PostEditBot Bridge">
            Сохранить
        </button>
    </form>
</div>

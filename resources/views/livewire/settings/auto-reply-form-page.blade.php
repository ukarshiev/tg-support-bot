<div>

    {{-- ── Breadcrumb top bar (consistent with other sub-pages) ───────────────────── --}}
    <div class="flex items-center border-b border-border-light bg-bg-primary px-6 lg:px-8" style="height:64px">
        <div class="flex items-center gap-3">
            <a href="{{ route('admin.settings.auto-replies') }}"
               class="flex items-center gap-1 text-text-secondary transition hover:text-text-primary"
               aria-label="Назад к автоответам">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 12H5m7-7-7 7 7 7" />
                </svg>
            </a>
            <a href="{{ route('admin.settings.auto-replies') }}"
               class="text-sm text-text-secondary transition hover:text-text-primary">
                Автоответы
            </a>
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-text-secondary" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
            </svg>
            <span class="text-sm font-semibold text-text-primary">
                {{ $isEdit ? 'Редактирование автоответа' : 'Новый автоответ' }}
            </span>
        </div>
    </div>

    {{-- ── Body ───────────────────────────────────────────────────────────────────── --}}
    <div class="p-6 lg:p-8">

        {{-- Card with rule parameters (design node B2aQ0u) --}}
        <div class="space-y-5 rounded-xl border border-border-light bg-bg-primary px-6 py-6 lg:px-7">

            <h2 class="text-base font-semibold text-text-primary">Параметры правила</h2>

            {{-- Trigger field (design node kriLU) --}}
            <x-admin.form-field
                label="Триггер"
                for="trigger"
                hint="Слово или фраза для активации автоответа"
                :error="$errors->first('trigger')"
            >
                <input
                    id="trigger"
                    type="text"
                    wire:model="trigger"
                    placeholder="Например: Привет"
                    autocomplete="off"
                    class="block h-[42px] w-full rounded-lg border bg-bg-primary px-3.5 text-sm text-text-primary placeholder-text-secondary outline-none transition focus:border-accent focus:ring-2 focus:ring-accent/20 {{ $errors->has('trigger') ? 'border-red-400' : 'border-border-light' }}"
                />
            </x-admin.form-field>

            {{-- Response text (design node g1ymYg) --}}
            <x-admin.form-field
                label="Текст ответа"
                for="response"
                hint="Поддерживается форматирование Markdown"
                :error="$errors->first('response')"
            >
                <textarea
                    id="response"
                    wire:model="response"
                    rows="5"
                    placeholder="Введите текст автоматического ответа..."
                    class="block h-[140px] w-full resize-none rounded-lg border bg-bg-primary p-3.5 text-sm text-text-primary placeholder-text-secondary outline-none transition focus:border-accent focus:ring-2 focus:ring-accent/20 {{ $errors->has('response') ? 'border-red-400' : 'border-border-light' }}"
                ></textarea>
            </x-admin.form-field>

            {{-- Active toggle row (design node lga3J) --}}
            <div class="flex items-center justify-between gap-3 pt-1">
                <span class="text-sm font-medium text-text-primary">Активировать</span>
                <x-admin.toggle wire:model="enabled" />
            </div>
        </div>

        {{-- Actions (design node lVE1M) --}}
        <div class="mt-6 flex items-center justify-end gap-3">
            <a href="{{ route('admin.settings.auto-replies') }}">
                <x-admin.button-secondary type="button">Отмена</x-admin.button-secondary>
            </a>
            <x-admin.button-primary type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save">Сохранить</span>
                <span wire:loading wire:target="save">Сохраняем...</span>
            </x-admin.button-primary>
        </div>
    </div>

</div>

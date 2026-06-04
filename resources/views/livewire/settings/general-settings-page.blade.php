<div class="p-6 lg:p-8">

    {{-- Page header --}}
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-text-primary">Основные</h1>
        <p class="mt-1 text-sm text-text-secondary">Общие настройки бота и параметры работы</p>
    </div>

    {{-- Card: Conversation settings --}}
    <x-admin.card title="Обращения">
        <form wire:submit="save" novalidate>
            @csrf

            <div class="space-y-5">

                {{-- Topic name template --}}
                <x-admin.form-field
                    label="Шаблон названия топика"
                    for="template_topic_name"
                    hint="Шаблон имени форум-темы в супергруппе Telegram для нового обращения"
                    :error="$formErrors['template_topic_name'] ?? null"
                >
                    <input
                        id="template_topic_name"
                        type="text"
                        wire:model="template_topic_name"
                        maxlength="255"
                        placeholder="Обращение"
                        class="block w-full rounded-lg border border-border-light bg-bg-input px-3.5 py-2.5 text-sm text-text-primary placeholder-text-secondary outline-none transition focus:border-accent focus:ring-2 focus:ring-accent/20 @if (!empty($formErrors['template_topic_name'])) border-red-400 @endif"
                    />
                </x-admin.form-field>

            </div>

            {{-- Action row --}}
            <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <x-admin.button-secondary wire:click="cancel" type="button">
                    Отмена
                </x-admin.button-secondary>
                <x-admin.button-primary type="submit">
                    Сохранить
                </x-admin.button-primary>
            </div>

            {{-- Success banner --}}
            @if ($saved)
                <div class="mt-4 flex items-center gap-2 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                    </svg>
                    Настройки сохранены.
                </div>
            @endif

        </form>
    </x-admin.card>

</div>

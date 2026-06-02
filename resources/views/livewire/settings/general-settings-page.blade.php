<div class="p-6 lg:p-8">

    {{-- Page header --}}
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-text-primary">Основные</h1>
        <p class="mt-1 text-sm text-text-secondary">Общие настройки бота и параметры работы</p>
    </div>

    {{-- Card: Bot info --}}
    <x-admin.card title="Информация о боте">
        <form wire:submit="save" novalidate>
            @csrf

            <div class="space-y-5">

                {{-- Bot name --}}
                <x-admin.form-field
                    label="Название бота"
                    for="bot_name"
                    :error="$formErrors['bot_name'] ?? null"
                >
                    <input
                        id="bot_name"
                        type="text"
                        wire:model="bot_name"
                        maxlength="255"
                        placeholder="TG Support Bot"
                        class="block w-full rounded-lg border border-border-light bg-bg-input px-3.5 py-2.5 text-sm text-text-primary placeholder-text-secondary outline-none transition focus:border-accent focus:ring-2 focus:ring-accent/20 @if (!empty($formErrors['bot_name'])) border-red-400 @endif"
                    />
                </x-admin.form-field>

                {{-- Description --}}
                <x-admin.form-field
                    label="Описание"
                    for="bot_description"
                    hint="Отображается в настройках клиента"
                    :error="$formErrors['bot_description'] ?? null"
                >
                    <textarea
                        id="bot_description"
                        wire:model="bot_description"
                        maxlength="1000"
                        rows="3"
                        class="block w-full resize-none rounded-lg border border-border-light bg-bg-input px-3.5 py-2.5 text-sm text-text-primary placeholder-text-secondary outline-none transition focus:border-accent focus:ring-2 focus:ring-accent/20 @if (!empty($formErrors['bot_description'])) border-red-400 @endif"
                    ></textarea>
                </x-admin.form-field>

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

                {{-- Manager interface --}}
                <x-admin.form-field
                    label="Интерфейс менеджера"
                    for="manager_interface"
                    :error="$formErrors['manager_interface'] ?? null"
                >
                    <div class="flex gap-3">
                        <label class="flex flex-1 cursor-pointer items-center gap-2.5 rounded-lg border px-4 py-3 transition
                            @if ($manager_interface === 'telegram_group') border-accent bg-accent/5 @else border-border-light bg-bg-input hover:border-accent/50 @endif">
                            <input
                                type="radio"
                                wire:model.live="manager_interface"
                                value="telegram_group"
                                class="accent-accent"
                            />
                            <span class="text-sm font-medium text-text-primary">telegram_group</span>
                        </label>
                        <label class="flex flex-1 cursor-pointer items-center gap-2.5 rounded-lg border px-4 py-3 transition
                            @if ($manager_interface === 'admin_panel') border-accent bg-accent/5 @else border-border-light bg-bg-input hover:border-accent/50 @endif">
                            <input
                                type="radio"
                                wire:model.live="manager_interface"
                                value="admin_panel"
                                class="accent-accent"
                            />
                            <span class="text-sm font-medium text-text-primary">admin_panel</span>
                        </label>
                    </div>
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

            {{-- Restart notice --}}
            @if ($showRestartNotice)
                <div class="mt-4 flex items-start gap-3 rounded-xl border border-yellow-200 bg-yellow-50 px-4 py-3 text-sm text-yellow-800">
                    <svg xmlns="http://www.w3.org/2000/svg" class="mt-0.5 h-4 w-4 shrink-0 text-yellow-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                    <span>
                        Изменение применится после перезапуска контейнера:
                        <code class="rounded bg-yellow-100 px-1 py-0.5 font-mono text-xs">docker compose restart app</code>
                    </span>
                </div>
            @endif

            {{-- Success banner --}}
            @if ($saved && ! $showRestartNotice)
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

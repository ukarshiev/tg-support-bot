<div class="p-4 lg:p-8">

    {{-- ── Page header ──────────────────────────────────────────────────────────── --}}
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-text-primary">Новый участник</h1>
        <p class="mt-1 text-sm text-text-secondary">Создайте оператора, задайте пароль и роль</p>
    </div>

    {{-- ── Form card ────────────────────────────────────────────────────────────── --}}
    <div class="rounded-xl border border-border-light bg-bg-primary p-4 lg:max-w-2xl lg:p-6">

        <div class="space-y-5">

            {{-- Avatar upload --}}
            <x-admin.form-field label="Фото" for="avatar" :error="$errors->first('avatar')">
                <div class="flex items-center gap-4">
                    {{-- Preview — only for previewable image types --}}
                    @if ($avatar && in_array($avatar->extension(), ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg']))
                        <img src="{{ $avatar->temporaryUrl() }}" alt="Предпросмотр" class="h-14 w-14 rounded-full object-cover">
                    @else
                        <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-bg-secondary text-text-secondary">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                            </svg>
                        </div>
                    @endif
                    <input
                        id="avatar"
                        type="file"
                        wire:model="avatar"
                        accept="image/*"
                        class="block text-sm text-text-secondary file:mr-3 file:rounded-lg file:border file:border-border-light file:bg-bg-primary file:px-3 file:py-1.5 file:text-sm file:text-text-primary file:transition file:hover:bg-bg-secondary"
                    >
                </div>
            </x-admin.form-field>

            {{-- Name --}}
            <x-admin.form-field label="Имя" for="name" :error="$errors->first('name')">
                <input
                    id="name"
                    type="text"
                    wire:model="name"
                    placeholder="Например: Пётр Иванов"
                    autocomplete="off"
                    class="block h-[42px] w-full rounded-lg border bg-bg-primary px-3.5 text-sm text-text-primary placeholder-text-secondary outline-none transition focus:border-accent focus:ring-2 focus:ring-accent/20 {{ $errors->has('name') ? 'border-red-400' : 'border-border-light' }}"
                />
            </x-admin.form-field>

            {{-- Email --}}
            <x-admin.form-field label="Email" for="email" :error="$errors->first('email')">
                <input
                    id="email"
                    type="email"
                    wire:model="email"
                    placeholder="operator@example.com"
                    autocomplete="off"
                    class="block h-[42px] w-full rounded-lg border bg-bg-primary px-3.5 text-sm text-text-primary placeholder-text-secondary outline-none transition focus:border-accent focus:ring-2 focus:ring-accent/20 {{ $errors->has('email') ? 'border-red-400' : 'border-border-light' }}"
                />
            </x-admin.form-field>

            {{-- Password --}}
            <x-admin.form-field label="Пароль" for="password" hint="Не короче 8 символов" :error="$errors->first('password')">
                <input
                    id="password"
                    type="password"
                    wire:model="password"
                    placeholder="••••••••"
                    autocomplete="new-password"
                    class="block h-[42px] w-full rounded-lg border bg-bg-primary px-3.5 text-sm text-text-primary placeholder-text-secondary outline-none transition focus:border-accent focus:ring-2 focus:ring-accent/20 {{ $errors->has('password') ? 'border-red-400' : 'border-border-light' }}"
                />
            </x-admin.form-field>

            {{-- Password confirmation --}}
            <x-admin.form-field label="Подтверждение пароля" for="password_confirmation" :error="$errors->first('password_confirmation')">
                <input
                    id="password_confirmation"
                    type="password"
                    wire:model="password_confirmation"
                    placeholder="••••••••"
                    autocomplete="new-password"
                    class="block h-[42px] w-full rounded-lg border border-border-light bg-bg-primary px-3.5 text-sm text-text-primary placeholder-text-secondary outline-none transition focus:border-accent focus:ring-2 focus:ring-accent/20"
                />
            </x-admin.form-field>

            {{-- Role --}}
            <x-admin.form-field label="Роль" for="role" :error="$errors->first('role')">
                <select
                    id="role"
                    wire:model="role"
                    class="block h-[42px] w-full rounded-lg border bg-bg-primary px-3.5 text-sm text-text-primary outline-none transition focus:border-accent focus:ring-2 focus:ring-accent/20 {{ $errors->has('role') ? 'border-red-400' : 'border-border-light' }}"
                >
                    <option value="">Выберите роль</option>
                    @foreach (\App\Enums\UserRole::options() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </x-admin.form-field>
        </div>

        {{-- Actions --}}
        <div class="mt-6 flex justify-end">
            <x-admin.button-primary type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save">Создать</span>
                <span wire:loading wire:target="save">Создание...</span>
            </x-admin.button-primary>
        </div>
    </div>

</div>

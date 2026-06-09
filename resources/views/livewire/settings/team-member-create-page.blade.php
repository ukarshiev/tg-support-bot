<div class="p-4 lg:p-8">

    {{-- ── Page header ──────────────────────────────────────────────────────────── --}}
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-text-primary">Новый участник</h1>
        <p class="mt-1 text-sm text-text-secondary">Создайте оператора, задайте пароль и роль</p>
    </div>

    {{-- ── Form card ────────────────────────────────────────────────────────────── --}}
    <div class="rounded-xl border border-border-light bg-bg-primary p-4 lg:max-w-2xl lg:p-6">

        <div class="space-y-5">

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

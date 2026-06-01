<div class="p-6 lg:p-8">

    {{-- ── Page header ──────────────────────────────────────────────────────────── --}}
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-text-primary">Команда</h1>
        <p class="mt-1 text-sm text-text-secondary">Управление операторами и ролями в команде поддержки</p>
    </div>

    {{-- ── Invite card ──────────────────────────────────────────────────────────── --}}
    <div class="mb-6 rounded-xl border border-border-light bg-bg-primary p-6 lg:px-7">
        <h2 class="mb-4 text-base font-semibold text-text-primary">Пригласить оператора</h2>

        {{-- Success notice --}}
        @if ($inviteSuccess)
            <div class="mb-4 flex items-center gap-2 rounded-lg border border-green-200 bg-green-50 px-4 py-3">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" style="color:#059669" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                </svg>
                <span class="text-sm text-green-800">{{ $inviteSuccess }}</span>
            </div>
        @endif

        {{-- Action-level error --}}
        @if ($inviteError)
            <div class="mb-4 flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3">
                <span class="text-sm text-red-700">{{ $inviteError }}</span>
            </div>
        @endif

        {{-- Form row: email + role + button — desktop side-by-side, mobile stacked --}}
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end">
            {{-- Email field --}}
            <div class="flex-1 space-y-1.5">
                <label for="inviteEmail" class="block text-[13px] font-medium text-text-primary">Email</label>
                <input
                    id="inviteEmail"
                    type="email"
                    wire:model="inviteEmail"
                    wire:keydown.enter="invite"
                    placeholder="operator@example.com"
                    autocomplete="off"
                    class="block w-full rounded-lg border bg-bg-primary px-3.5 py-2.5 text-sm text-text-primary placeholder-text-secondary outline-none transition focus:border-accent focus:ring-2 focus:ring-accent/20 {{ $errors->has('inviteEmail') ? 'border-red-400' : 'border-border-light' }}"
                />
                @error('inviteEmail')
                    <p class="text-xs text-red-500">{{ $message }}</p>
                @enderror
            </div>

            {{-- Role select --}}
            <div class="w-full space-y-1.5 sm:w-48">
                <label for="inviteRole" class="block text-[13px] font-medium text-text-primary">Роль</label>
                <select
                    id="inviteRole"
                    wire:model="inviteRole"
                    class="block w-full rounded-lg border bg-bg-primary px-3.5 py-2.5 text-sm text-text-primary outline-none transition focus:border-accent focus:ring-2 focus:ring-accent/20 {{ $errors->has('inviteRole') ? 'border-red-400' : 'border-border-light' }}"
                >
                    <option value="">Выберите роль</option>
                    @foreach (\App\Enums\UserRole::options() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                @error('inviteRole')
                    <p class="text-xs text-red-500">{{ $message }}</p>
                @enderror
            </div>

            {{-- Send button --}}
            <x-admin.button-primary
                type="button"
                wire:click="invite"
                wire:loading.attr="disabled"
                wire:target="invite"
                class="shrink-0"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="mr-1.5 h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
                </svg>
                <span wire:loading.remove wire:target="invite">Отправить приглашение</span>
                <span wire:loading wire:target="invite">Отправляем...</span>
            </x-admin.button-primary>
        </div>
    </div>

    {{-- ── Members table ────────────────────────────────────────────────────────── --}}
    <div class="rounded-xl border border-border-light bg-bg-primary">

        {{-- Table header --}}
        <div class="flex items-center px-6 py-4">
            <h2 class="text-base font-semibold text-text-primary">Участники команды</h2>
        </div>
        <div class="border-t border-border-light"></div>

        {{-- Column headers — hidden on mobile --}}
        <div class="hidden grid-cols-[1fr_200px_120px_60px] items-center bg-[#FAFAFA] px-6 py-3 text-[12px] font-medium text-text-secondary lg:grid">
            <span>Участник</span>
            <span>Роль</span>
            <span>Статус</span>
            <span class="text-center">Действия</span>
        </div>
        <div class="hidden border-t border-border-light lg:block"></div>

        {{-- Delete error notice --}}
        @if ($deleteError)
            <div class="border-b border-border-light bg-red-50 px-6 py-3">
                <p class="text-sm text-red-700">{{ $deleteError }}</p>
            </div>
        @endif

        {{-- Member rows --}}
        @forelse ($members as $member)
            @php
                /** @var \App\Models\User $member */
                $isSelf = Auth::id() === $member->id;
                $avatarColor = $this->avatarColor($member);
                $initials = $this->avatarInitials($member);
                $roleLabel = $member->role->label();
                $isAdmin = $member->role === \App\Enums\UserRole::Admin;
            @endphp

            {{-- Divider (not before first row) --}}
            @if (! $loop->first)
                <div class="border-t border-border-light"></div>
            @endif

            {{-- Desktop: grid row --}}
            <div class="hidden grid-cols-[1fr_200px_120px_60px] items-center px-6 py-3.5 lg:grid
                        {{ $confirmDeleteId === $member->id ? 'bg-red-50' : '' }}">

                {{-- Participant column --}}
                <div class="flex items-center gap-3">
                    {{-- Avatar circle with initials --}}
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-[12px] font-semibold text-white"
                        style="background: {{ $avatarColor }}"
                        aria-hidden="true"
                    >{{ $initials }}</div>

                    <div class="min-w-0">
                        <p class="truncate text-[13px] font-medium text-text-primary">
                            {{ $member->name !== '' ? $member->name : '—' }}
                        </p>
                        <p class="truncate text-[12px] text-text-secondary">{{ $member->email }}</p>
                    </div>
                </div>

                {{-- Role column --}}
                <div>
                    <span class="text-[13px] text-text-primary">{{ $roleLabel }}</span>
                </div>

                {{-- Status column — v1 stub, no real online tracking yet --}}
                <div>
                    <span class="inline-flex items-center rounded-md px-2.5 py-1 text-[12px] font-normal"
                          style="background:#F3F4F6; color:#6B7280"
                          title="Статус онлайн появится в следующей версии">
                        —
                    </span>
                </div>

                {{-- Actions column --}}
                <div class="flex items-center justify-center">
                    @if ($confirmDeleteId === $member->id)
                        {{-- Inline confirmation --}}
                        <div class="flex items-center gap-1.5">
                            <button
                                wire:click="deleteMember"
                                class="rounded px-2 py-1 text-[12px] font-medium text-white transition"
                                style="background:#EF4444"
                                title="Подтвердить удаление"
                            >Удалить</button>
                            <button
                                wire:click="cancelDelete"
                                class="rounded border border-border-light px-2 py-1 text-[12px] font-medium text-text-secondary transition hover:bg-bg-secondary"
                            >Отмена</button>
                        </div>
                    @elseif (! $isSelf)
                        <button
                            wire:click="confirmDelete({{ $member->id }})"
                            class="flex h-8 w-8 items-center justify-center rounded-lg text-text-secondary transition hover:bg-bg-secondary hover:text-red-500"
                            title="Удалить участника"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                            </svg>
                        </button>
                    @else
                        {{-- Self: no delete action shown --}}
                        <span class="h-8 w-8"></span>
                    @endif
                </div>
            </div>

            {{-- Mobile: card row --}}
            <div class="flex items-center justify-between px-4 py-3.5 lg:hidden
                        {{ $confirmDeleteId === $member->id ? 'bg-red-50' : '' }}">
                <div class="flex items-center gap-3">
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-[12px] font-semibold text-white"
                        style="background: {{ $avatarColor }}"
                        aria-hidden="true"
                    >{{ $initials }}</div>
                    <div class="min-w-0">
                        <p class="truncate text-[13px] font-medium text-text-primary">
                            {{ $member->name !== '' ? $member->name : '—' }}
                        </p>
                        <p class="truncate text-[12px] text-text-secondary">{{ $member->email }}</p>
                        <span class="mt-0.5 inline-block text-[11px] text-text-secondary">{{ $roleLabel }}</span>
                    </div>
                </div>

                @if ($confirmDeleteId === $member->id)
                    <div class="flex shrink-0 items-center gap-1.5">
                        <button wire:click="deleteMember"
                            class="rounded px-2 py-1 text-[12px] font-medium text-white"
                            style="background:#EF4444">Удалить</button>
                        <button wire:click="cancelDelete"
                            class="rounded border border-border-light px-2 py-1 text-[12px] font-medium text-text-secondary">Отмена</button>
                    </div>
                @elseif (! $isSelf)
                    <button
                        wire:click="confirmDelete({{ $member->id }})"
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-text-secondary transition hover:bg-bg-secondary hover:text-red-500"
                        title="Удалить участника"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                        </svg>
                    </button>
                @else
                    <span class="h-8 w-8 shrink-0"></span>
                @endif
            </div>

        @empty
            <div class="px-6 py-12 text-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="mx-auto mb-3 h-8 w-8 text-text-secondary" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
                <p class="text-sm text-text-secondary">Участников пока нет. Пригласите первого оператора выше.</p>
            </div>
        @endforelse

    </div>

</div>

<div class="p-4 lg:p-8">

    {{-- ── Page header + add button ─────────────────────────────────────────────── --}}
    <div class="mb-6 flex items-start justify-between gap-3">
        <div class="min-w-0">
            <h1 class="text-2xl font-bold text-text-primary">Команда</h1>
            <p class="mt-1 text-sm text-text-secondary">Управление операторами и ролями в команде поддержки</p>
        </div>

        <a href="{{ route('admin.settings.team.create') }}"
           class="inline-flex shrink-0 items-center justify-center rounded-[10px] bg-accent px-5 py-2.5 text-sm font-medium text-white transition hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="mr-1.5 h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
            </svg>
            Добавить
        </a>
    </div>

    {{-- ── Members table ────────────────────────────────────────────────────────── --}}
    <div class="rounded-xl border border-border-light bg-bg-primary">

        {{-- Table header --}}
        <div class="flex items-center px-4 py-4 lg:px-6">
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
            <div class="border-b border-border-light bg-red-50 px-4 py-3 lg:px-6">
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
                $memberLabel = $member->name !== '' ? $member->name : $member->email;
                $editUrl = route('admin.settings.team.edit', $member->id);
            @endphp

            {{-- Divider (not before first row) --}}
            @if (! $loop->first)
                <div class="border-t border-border-light"></div>
            @endif

            {{-- Desktop: grid row --}}
            <div class="relative hidden grid-cols-[1fr_200px_120px_60px] items-center px-6 py-3.5 transition hover:bg-bg-secondary/40 lg:grid">

                {{-- Row-wide edit link (stretched; the delete button sits above it) --}}
                <a href="{{ $editUrl }}" class="absolute inset-0" aria-label="Редактировать «{{ $memberLabel }}»"></a>

                {{-- Participant column --}}
                <div class="flex items-center gap-3">
                    @if ($member->avatar_path)
                        <img
                            src="{{ route('admin.team-member-avatar', $member->id) }}"
                            alt="{{ $memberLabel }}"
                            class="h-9 w-9 shrink-0 rounded-full object-cover"
                            aria-hidden="true"
                        >
                    @else
                        <div
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-[12px] font-semibold text-white"
                            style="background: {{ $avatarColor }}"
                            aria-hidden="true"
                        >{{ $initials }}</div>
                    @endif

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
                <div class="relative z-10 flex items-center justify-center">
                    @unless ($isSelf)
                        <button
                            type="button"
                            wire:click="deleteMember({{ $member->id }})"
                            wire:confirm="Удалить участника «{{ $memberLabel }}»? Действие необратимо."
                            class="flex h-8 w-8 items-center justify-center rounded-lg text-text-secondary transition hover:bg-bg-secondary hover:text-red-500"
                            title="Удалить участника"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                            </svg>
                        </button>
                    @else
                        {{-- Self: delete disabled (cannot delete own account) --}}
                        <span class="flex h-8 w-8 items-center justify-center rounded-lg text-text-secondary opacity-40"
                              title="Нельзя удалить свой аккаунт" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                            </svg>
                        </span>
                    @endunless
                </div>
            </div>

            {{-- Mobile: card row --}}
            <div class="relative flex items-center justify-between gap-2 px-4 py-3.5 transition hover:bg-bg-secondary/40 lg:hidden">
                <a href="{{ $editUrl }}" class="absolute inset-0" aria-label="Редактировать «{{ $memberLabel }}»"></a>
                <div class="flex min-w-0 items-center gap-3">
                    @if ($member->avatar_path)
                        <img
                            src="{{ route('admin.team-member-avatar', $member->id) }}"
                            alt="{{ $memberLabel }}"
                            class="h-9 w-9 shrink-0 rounded-full object-cover"
                            aria-hidden="true"
                        >
                    @else
                        <div
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-[12px] font-semibold text-white"
                            style="background: {{ $avatarColor }}"
                            aria-hidden="true"
                        >{{ $initials }}</div>
                    @endif
                    <div class="min-w-0">
                        <p class="truncate text-[13px] font-medium text-text-primary">
                            {{ $member->name !== '' ? $member->name : '—' }}
                        </p>
                        <p class="truncate text-[12px] text-text-secondary">{{ $member->email }}</p>
                        <span class="mt-0.5 inline-block text-[11px] text-text-secondary">{{ $roleLabel }}</span>
                    </div>
                </div>

                @unless ($isSelf)
                    <button
                        type="button"
                        wire:click="deleteMember({{ $member->id }})"
                        wire:confirm="Удалить участника «{{ $memberLabel }}»? Действие необратимо."
                        class="relative z-10 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-text-secondary transition hover:bg-bg-secondary hover:text-red-500"
                        title="Удалить участника"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                        </svg>
                    </button>
                @else
                    {{-- Self: delete disabled (cannot delete own account) --}}
                    <span class="relative z-10 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-text-secondary opacity-40"
                          title="Нельзя удалить свой аккаунт" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                        </svg>
                    </span>
                @endunless
            </div>

        @empty
            <div class="px-6 py-12 text-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="mx-auto mb-3 h-8 w-8 text-text-secondary" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
                <p class="text-sm text-text-secondary">Участников пока нет. Нажмите «Добавить», чтобы создать первого оператора.</p>
            </div>
        @endforelse

    </div>

</div>

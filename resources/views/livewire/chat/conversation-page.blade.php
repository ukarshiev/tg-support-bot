{{--
    Manager Chat Workspace — full-screen 3-column layout
    ─────────────────────────────────────────────────────────────────────────────
    Design: admin-tg-support-bot.pen  node S4QeyR (desktop), AwhSc (media),
            K1dijv / lo3D5 (mobile)
    ─────────────────────────────────────────────────────────────────────────────
    Desktop  [Left 360px | Center flex-1 | Right 300px]
    Tablet   [Left 360px | Center flex-1 ]   (right panel hidden < lg)
    Mobile   list-only ↔ chat screen  (toggled by activeBotUserId)
    ─────────────────────────────────────────────────────────────────────────────
    Polling: 5 s (wire:poll.5s → pollUpdates: refresh list + active thread)
--}}

<div
    class="flex h-screen overflow-hidden"
    wire:poll.5s="pollUpdates"
    x-data="{ lightboxSrc: '', lightboxOpen: false, infoPanelOpen: false }"
    x-on:open-lightbox.window="lightboxSrc = $event.detail.src; lightboxOpen = true"
    x-on:messages-updated.window="$nextTick(() => {
        const thread = document.getElementById('chat-thread');
        if (thread) thread.scrollTop = thread.scrollHeight;
    })"
>

    {{-- ── Lightbox overlay ──────────────────────────────────────────────────── --}}
    <template x-teleport="body">
        <div
            x-on:click="lightboxOpen = false"
            x-on:keydown.escape.window="lightboxOpen = false"
            class="fixed inset-0 z-[100000] flex items-center justify-center cursor-zoom-out"
            :class="lightboxOpen ? 'pointer-events-auto' : 'pointer-events-none'"
            :style="{ opacity: lightboxOpen ? 1 : 0, transition: 'opacity 300ms ease' }"
            style="opacity:0"
        >
            <div class="absolute inset-0 bg-black" style="opacity:0.85"></div>
            <button
                x-on:click.stop="lightboxOpen = false"
                class="fixed top-16 right-4 text-white text-4xl leading-none opacity-80 hover:opacity-100 bg-transparent border-none cursor-pointer z-[100001]"
                aria-label="Закрыть"
            >&times;</button>
            <img
                :src="lightboxSrc"
                x-on:click.stop
                class="relative z-[100001] object-contain rounded-lg shadow-2xl cursor-default block"
                style="max-width:min(85vw,960px);max-height:80vh"
                alt="Просмотр изображения"
            >
        </div>
    </template>

    {{-- ══ LEFT SIDEBAR — Dialog list ════════════════════════════════════════ --}}
    {{--
        Design: node M1Hgk — bg-sidebar #1B1F2E, width 360, padding [20,16,0,16], gap 16
        Mobile: visible when no dialog is selected
        Desktop: always visible
    --}}
    <aside
        class="flex shrink-0 flex-col bg-sidebar
               w-full md:w-[360px]
               {{ $activeBotUserId ? 'hidden md:flex' : 'flex' }}"
        style="gap:16px; padding: 20px 16px 0 16px;"
    >
        {{-- Header: "TG Support" + settings gear --}}
        {{-- Design: node AQ4LA — space-between, white 20/700 + settings icon #8B92A5 --}}
        <div class="flex items-center justify-between w-full shrink-0">
            <span class="text-text-sidebar font-bold" style="font-size:20px; line-height:1.2;">TG Support</span>
            <div class="flex items-center shrink-0" style="gap:4px;">
            <a
                href="{{ route('admin.settings.general') }}"
                wire:navigate
                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-text-sidebar-secondary transition hover:bg-sidebar-active hover:text-text-sidebar"
                aria-label="Настройки"
                title="Настройки"
            >
                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    width="20" height="20"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                >
                    <path d="M12.22 2h-.44a2 2 0 00-2 2v.18a2 2 0 01-1 1.73l-.43.25a2 2 0 01-2 0l-.15-.08a2 2 0 00-2.73.73l-.22.38a2 2 0 00.73 2.73l.15.1a2 2 0 011 1.72v.51a2 2 0 01-1 1.74l-.15.09a2 2 0 00-.73 2.73l.22.38a2 2 0 002.73.73l.15-.08a2 2 0 012 0l.43.25a2 2 0 011 1.73V20a2 2 0 002 2h.44a2 2 0 002-2v-.18a2 2 0 011-1.73l.43-.25a2 2 0 012 0l.15.08a2 2 0 002.73-.73l.22-.39a2 2 0 00-.73-2.73l-.15-.08a2 2 0 01-1-1.74v-.5a2 2 0 011-1.74l.15-.09a2 2 0 00.73-2.73l-.22-.38a2 2 0 00-2.73-.73l-.15.08a2 2 0 01-2 0l-.43-.25a2 2 0 01-1-1.73V4a2 2 0 00-2-2z"/>
                    <circle cx="12" cy="12" r="3"/>
                </svg>
            </a>
            <form method="POST" action="{{ route('filament.admin.auth.logout') }}">
                @csrf
                <button
                    type="submit"
                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-text-sidebar-secondary transition hover:bg-sidebar-active hover:text-text-sidebar"
                    aria-label="Выйти"
                    title="Выйти"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
                        <polyline points="16 17 21 12 16 7" />
                        <line x1="21" x2="9" y1="12" y2="12" />
                    </svg>
                </button>
            </form>
            </div>
        </div>

        {{-- Search bar: bg-sidebar-active #2D3348, rounded-8, search icon + placeholder --}}
        {{-- Design: node czMC8 — fill bg-sidebar-active, cornerRadius 8, padding [10,12], gap 8 --}}
        <div class="relative shrink-0">
            <div class="flex items-center gap-2 rounded-lg bg-sidebar-active w-full" style="padding: 10px 12px; border-radius:8px;">
                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    class="shrink-0 text-text-sidebar-secondary"
                    width="16" height="16"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                >
                    <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
                </svg>
                <input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Поиск чатов..."
                    class="flex-1 bg-transparent text-sm text-text-sidebar placeholder-text-sidebar-secondary outline-none border-none"
                    aria-label="Поиск чатов"
                >
            </div>
        </div>

        {{-- Filter tabs: pill-style. Active = bg-accent text-white rounded-md 13/600 --}}
        {{-- Design: node YYO8P — gap 4; BitoF active pill bg-accent; others transparent text-sidebar-secondary --}}
        <div class="flex shrink-0" style="gap:4px;">
            @foreach(['all' => 'Все', 'open' => 'Открытые', 'closed' => 'Закрытые'] as $value => $label)
                <button
                    wire:click="$set('statusFilter', '{{ $value }}')"
                    class="rounded-md transition-colors cursor-pointer"
                    style="padding: 6px 12px; font-size:13px; font-weight: {{ $statusFilter === $value ? '600' : '400' }}; {{ $statusFilter === $value ? 'background:#4F6EF7; color:#FFFFFF;' : 'background:transparent; color:#8B92A5;' }}"
                    type="button"
                >{{ $label }}</button>
            @endforeach
        </div>

        {{-- Divider --}}
        {{-- Design: node FvpDg — bg border-sidebar #2D3348, height 1 --}}
        <div class="w-full shrink-0 h-px bg-border-sidebar"></div>

        {{-- Dialog list --}}
        <div class="flex-1 overflow-y-auto -mx-4 pb-4">
            @forelse($dialogList as $user)
                <div
                    wire:click="selectChat({{ $user->id }})"
                    wire:key="dialog-{{ $user->id }}"
                    class="cursor-pointer"
                >
                    <x-chat-item
                        :bot-user="$user"
                        :is-active="$activeBotUserId === $user->id"
                        :has-unread="$this->hasUnread($user)"
                    />
                </div>
            @empty
                <p class="px-4 py-6 text-center text-sm text-text-sidebar-secondary">Нет диалогов</p>
            @endforelse
        </div>
    </aside>

    {{-- ══ CENTER — Chat area ═════════════════════════════════════════════════ --}}
    {{-- Design: node YMMFj — bg-primary, layout vertical, fill_container --}}
    <main
        class="flex flex-1 flex-col bg-bg-primary overflow-hidden
               {{ $activeBotUserId ? 'flex' : 'hidden md:flex' }}"
    >
        @if($activeBotUser)

            {{-- Chat header --}}
            {{-- Design: node xQRXL — height 72, padding [0,24], space-between, bg-primary --}}
            <div class="flex items-center justify-between shrink-0 bg-bg-primary border-b border-border-light" style="height:72px; padding: 0 24px;">

                {{-- Header Left: mobile back + avatar + name + platform --}}
                {{-- Design: node nQRoU — gap 14, alignItems center --}}
                <div class="flex items-center" style="gap:14px;">

                    {{-- Mobile back button --}}
                    <button
                        wire:click="selectChat(0)"
                        class="flex h-8 w-8 items-center justify-center rounded-lg text-text-secondary transition hover:bg-bg-secondary md:hidden shrink-0"
                        aria-label="Назад к списку"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                        </svg>
                    </button>

                    {{-- User avatar (42×42 ellipse) with initials --}}
                    @php
                        $hdrColors = ['#6366F1','#E85D75','#34C759','#F5A623','#06B6D4','#10B981','#8B5CF6','#EF4444'];
                        $hdrIdx = abs(crc32((string) $activeBotUser->chat_id)) % 8;
                        $hdrColor = $hdrColors[$hdrIdx];
                        $hdrInitials = strtoupper(substr((string) $activeBotUser->chat_id, 0, 2));
                    @endphp
                    <div
                        class="relative shrink-0 flex items-center justify-center rounded-full text-white font-semibold select-none"
                        style="width:42px; height:42px; background:{{ $hdrColor }}; font-size:16px;"
                        aria-hidden="true"
                    >{{ $hdrInitials }}</div>

                    {{-- Name + platform sub-line --}}
                    {{-- Design: node O6aEj — vertical layout gap 2 --}}
                    <div class="flex flex-col" style="gap:2px;">
                        <span class="text-sm font-semibold text-text-primary leading-tight truncate max-w-[280px]">
                            {{ $activeBotUser->chat_id }}
                        </span>
                        @php
                            $platformLabel = match ($activeBotUser->platform) {
                                'telegram' => 'Telegram',
                                'vk'       => 'VK',
                                'max'      => 'Max',
                                default    => ucfirst($activeBotUser->platform),
                            };
                        @endphp
                        <span class="text-xs text-text-secondary leading-tight">
                            {{ '@' . $activeBotUser->chat_id }} · {{ $platformLabel }}
                        </span>
                    </div>
                </div>

                {{-- Header Actions: search + more --}}
                {{-- Design: node r6DYj — gap 4, align center --}}
                <div class="flex items-center" style="gap:4px;">
                    <div class="flex items-center justify-center rounded-lg text-text-secondary" style="width:36px; height:36px;" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
                        </svg>
                    </div>
                    <button
                        type="button"
                        x-on:click="infoPanelOpen = !infoPanelOpen"
                        :class="infoPanelOpen ? 'bg-bg-secondary text-accent' : 'text-text-secondary hover:bg-bg-secondary'"
                        :aria-pressed="infoPanelOpen"
                        class="flex items-center justify-center rounded-lg transition"
                        style="width:36px; height:36px; border:none; cursor:pointer; background:transparent;"
                        aria-label="Сведения о клиенте"
                        title="Сведения о клиенте"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/>
                        </svg>
                    </button>
                </div>
            </div>

            {{-- Message thread --}}
            {{-- Design: node bCcH9 — bg-secondary, padding [24,32], gap 16, bottom-stacked via inner mt-auto, fill_container --}}
            <div
                id="chat-thread"
                class="flex flex-col flex-1 overflow-y-auto bg-bg-secondary"
                style="padding:24px 32px;"
                x-data
                x-init="$el.scrollTop = $el.scrollHeight"
            >
                @if($chatMessages->isEmpty())
                    <div class="flex flex-1 items-center justify-center">
                        <p class="text-sm text-text-secondary">Нет сообщений</p>
                    </div>
                @else
                {{-- mt-auto pins messages to the bottom when they don't fill the
                     viewport, while keeping the container a plain top-anchored
                     scroll area so overflow stays reachable (justify-end clips it). --}}
                <div class="flex flex-col mt-auto w-full" style="gap:16px;">
                @foreach($chatMessages as $message)
                    @php
                        $msgDate   = $message->created_at?->toDateString();
                        $prevDate  = $loop->first ? null : $chatMessages[$loop->index - 1]->created_at?->toDateString();
                        $today     = \Carbon\Carbon::today()->toDateString();
                        $yesterday = \Carbon\Carbon::yesterday()->toDateString();

                        $showDateSep = $loop->first || $msgDate !== $prevDate;
                        if ($showDateSep && $msgDate) {
                            $dateLabel = match(true) {
                                $msgDate === $today     => 'Сегодня',
                                $msgDate === $yesterday => 'Вчера',
                                default => \Carbon\Carbon::parse($msgDate)->locale('ru')->isoFormat('D MMMM YYYY'),
                            };
                        }
                    @endphp

                    {{-- Date separator --}}
                    {{-- Design: node AG5V7 — gap 16, center, lines + date text --}}
                    @if($showDateSep && $msgDate)
                        <div class="flex items-center w-full" style="gap:16px;">
                            <span class="flex-1 border-t border-border-light"></span>
                            <span class="text-xs text-text-secondary whitespace-nowrap" style="font-size:12px;">{{ $dateLabel }}</span>
                            <span class="flex-1 border-t border-border-light"></span>
                        </div>
                    @endif

                    {{-- Message bubble --}}
                    @php
                        $isOutgoing = $message->message_type === 'outgoing';
                        $messageText = $message->text ?? $message->externalMessage?->text;
                    @endphp

                    @if($isOutgoing)
                        {{-- Outgoing: right-aligned, bg-accent (#4F6EF7), white text, cornerRadius [16,16,4,16] --}}
                        {{-- Design: node uduZU / Bubble Out --}}
                        <div class="flex w-full" style="justify-content:flex-end;">
                            <div class="flex flex-col" style="border-radius:16px 16px 4px 16px; background:#4F6EF7; padding:10px 14px; gap:4px; max-width:70%;">
                                @if($message->attachments->isNotEmpty())
                                    <x-message-attachments
                                        :attachments="$message->attachments"
                                        :platform="$message->platform"
                                        :is-outgoing="true"
                                    />
                                @endif
                                @if($messageText)
                                    <p class="text-sm text-white" style="font-size:14px; line-height:1.4;">{{ $messageText }}</p>
                                @elseif($message->attachments->isEmpty())
                                    <p class="text-xs text-white opacity-70 italic">{{ $message->platform }} · {{ $message->message_type }}</p>
                                @endif
                                <p class="text-right text-white opacity-70" style="font-size:11px;">
                                    {{ $message->created_at?->format('H:i') }}
                                </p>
                            </div>
                        </div>
                    @else
                        {{-- Incoming: left-aligned with small avatar, bg-primary / bg-input bubble, cornerRadius [16,16,16,4] --}}
                        {{-- Design: node HGFji / Bubble In -- avatar 32×32 ellipse + bubble --}}
                        <div class="flex items-end w-full" style="gap:10px;">
                            {{-- Small avatar --}}
                            <div
                                class="flex shrink-0 items-center justify-center rounded-full text-white font-semibold select-none"
                                style="width:32px; height:32px; background:{{ $hdrColor }}; font-size:11px;"
                                aria-hidden="true"
                            >{{ $hdrInitials }}</div>
                            {{-- Bubble --}}
                            <div class="flex flex-col" style="border-radius:16px 16px 16px 4px; background:#FFFFFF; border:1px solid #E5E7EB; padding:10px 14px; gap:4px; max-width:70%;">
                                @if($message->attachments->isNotEmpty())
                                    <x-message-attachments
                                        :attachments="$message->attachments"
                                        :platform="$message->platform"
                                        :is-outgoing="false"
                                    />
                                @endif
                                @if($messageText)
                                    <p class="text-sm text-text-primary" style="font-size:14px; line-height:1.4;">{{ $messageText }}</p>
                                @elseif($message->attachments->isEmpty())
                                    <p class="text-xs text-text-secondary italic opacity-60">{{ $message->platform }} · {{ $message->message_type }}</p>
                                @endif
                                <p class="text-text-secondary opacity-70" style="font-size:11px;">
                                    {{ $message->created_at?->format('H:i') }}
                                </p>
                            </div>
                        </div>
                    @endif
                @endforeach
                </div>
                @endif
            </div>

            {{-- Input area --}}
            {{-- Design: node zONmD — height 72, bg-primary, padding [12,24], gap 12, border-top --}}
            @if($this->shouldShowReplyForm())
                <div class="shrink-0 bg-bg-primary border-t border-border-light" style="padding: 12px 24px;">

                    {{-- Quick-reply chips --}}
                    {{-- Design: mobile lo3D5 node bQdAT — chips row above input --}}
                    @php $quickReplies = config('chat.quick_replies', []) @endphp
                    @if(!empty($quickReplies))
                        <div class="flex flex-wrap mb-2" style="gap:8px;">
                            @foreach($quickReplies as $reply)
                                <button
                                    type="button"
                                    wire:click="insertQuickReply('{{ addslashes($reply) }}')"
                                    class="text-text-secondary transition hover:text-accent"
                                    style="border-radius:16px; background:#F1F3F5; padding:6px 12px; font-size:12px; border:none; cursor:pointer;"
                                >{{ $reply }}</button>
                            @endforeach
                        </div>
                    @endif

                    {{-- Selected attachment preview / upload progress (telegram + vk only) --}}
                    @if($this->supportsAttachments())
                        {{-- Uploading spinner --}}
                        <div
                            wire:loading.flex
                            wire:target="attachment"
                            class="items-center mb-2"
                            style="gap:8px;"
                        >
                            <svg class="animate-spin text-accent" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M21 12a9 9 0 1 1-6.219-8.56"/>
                            </svg>
                            <span class="text-text-secondary" style="font-size:12px;">Загрузка файла…</span>
                        </div>

                        {{-- Selected file chip --}}
                        @if($attachment)
                            <div wire:loading.remove wire:target="attachment" class="flex items-center mb-2" style="gap:8px;">
                                <div class="flex items-center max-w-full" style="gap:8px; background:#EEF1FE; border:1px solid #D5DBF9; border-radius:8px; padding:6px 10px;">
                                    <svg class="shrink-0 text-accent" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/>
                                    </svg>
                                    <span class="truncate text-text-primary" style="font-size:12px; max-width:220px;">{{ $attachment->getClientOriginalName() }}</span>
                                    <button
                                        type="button"
                                        wire:click="removeAttachment"
                                        class="flex shrink-0 items-center justify-center text-text-secondary transition hover:text-red-500"
                                        style="width:16px; height:16px; border:none; background:transparent; cursor:pointer; line-height:1; font-size:16px;"
                                        aria-label="Убрать файл"
                                        title="Убрать файл"
                                    >&times;</button>
                                </div>
                            </div>
                        @endif

                        @error('attachment')
                            <p class="mb-2 text-xs text-red-500">{{ $message }}</p>
                        @enderror
                    @endif

                    {{-- Input row: attach + textarea + send --}}
                    {{-- Design: FEYxe (attach 40×40) + Euru3 (input, fill, bg-input, rounded-12) + K6yaRa (send 44×44 bg-accent rounded-12) --}}
                    <form wire:submit.prevent="sendReply" class="flex items-center" style="gap:12px;">

                        {{-- Attach button (telegram + vk only) --}}
                        @if($this->supportsAttachments())
                            <label
                                class="flex items-center justify-center shrink-0 text-text-secondary cursor-pointer transition hover:text-accent hover:bg-bg-secondary"
                                style="width:40px; height:40px; border-radius:10px;"
                                title="Прикрепить файл"
                                aria-label="Прикрепить файл"
                            >
                                <input type="file" wire:model="attachment" class="hidden" aria-hidden="true">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 18 8.84l-8.59 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48"/>
                                </svg>
                            </label>
                        @endif

                        {{-- Text input --}}
                        <div class="relative flex-1">
                            <textarea
                                wire:model.live="replyText"
                                rows="1"
                                placeholder="Напишите сообщение..."
                                class="w-full resize-none text-sm text-text-primary placeholder-text-secondary outline-none border-none bg-transparent"
                                style="background:#F1F3F5; border-radius:12px; padding:12px 16px; height:44px; line-height:1.25; overflow:hidden;"
                                x-on:keydown.ctrl.enter="$wire.sendReply()"
                                aria-label="Текст сообщения"
                            ></textarea>
                            @error('replyText')
                                <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                            @enderror
                        </div>

                        {{-- Send button --}}
                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            wire:target="sendReply,attachment"
                            class="flex shrink-0 items-center justify-center text-white transition hover:opacity-90 disabled:opacity-50"
                            style="width:44px; height:44px; border-radius:12px; background:#4F6EF7; border:none; cursor:pointer;"
                            aria-label="Отправить"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="m3 3 3 9-3 9 19-9Z"/><path d="M6 12h16"/>
                            </svg>
                        </button>
                    </form>
                </div>
            @endif

        @else
            {{-- Empty state --}}
            <div class="flex flex-1 flex-col items-center justify-center text-text-secondary">
                <svg xmlns="http://www.w3.org/2000/svg" class="mb-3 opacity-30" width="48" height="48" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                </svg>
                <p class="text-sm">Выберите диалог</p>
            </div>
        @endif
    </main>

    {{-- ══ RIGHT PANEL — User info & media gallery ════════════════════════════ --}}
    {{-- Design: node VdLTH — width 300, padding [24,20], gap 20, bg-primary, border-left --}}
    @if($activeBotUser)
        <aside
            x-show="infoPanelOpen"
            x-cloak
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            class="flex shrink-0 flex-col bg-bg-primary overflow-y-auto border-l border-border-light"
            style="width:300px; gap:20px; padding:24px 20px;"
        >

            {{-- Profile section --}}
            {{-- Design: node KAbQg — vertical, gap 14, align center --}}
            <div class="flex flex-col items-center" style="gap:14px;">

                {{-- Large avatar 64×64 --}}
                @php
                    $rpColors = ['#6366F1','#E85D75','#34C759','#F5A623','#06B6D4','#10B981','#8B5CF6','#EF4444'];
                    $rpIdx = abs(crc32((string) $activeBotUser->chat_id)) % 8;
                    $rpColor = $rpColors[$rpIdx];
                    $rpInitials = strtoupper(substr((string) $activeBotUser->chat_id, 0, 2));
                @endphp
                <div
                    class="flex items-center justify-center rounded-full text-white font-semibold select-none shrink-0"
                    style="width:64px; height:64px; background:{{ $rpColor }}; font-size:22px; border-radius:32px;"
                    aria-hidden="true"
                >{{ $rpInitials }}</div>

                {{-- Name + handle --}}
                {{-- Design: node wAn8z — vertical, gap 4, center --}}
                <div class="flex flex-col items-center w-full" style="gap:4px;">
                    <span class="text-text-primary font-semibold text-center" style="font-size:16px;">
                        {{ $activeBotUser->chat_id }}
                    </span>
                    @php
                        $rpPlatformLabel = match ($activeBotUser->platform) {
                            'telegram' => 'Telegram',
                            'vk'       => 'VK',
                            'max'      => 'Max',
                            default    => ucfirst($activeBotUser->platform),
                        };
                    @endphp
                    <span class="text-text-secondary text-center" style="font-size:13px;">
                        {{ '@' . $activeBotUser->chat_id }} · {{ $rpPlatformLabel }}
                    </span>
                </div>

                {{-- Action buttons: Блок + Закрыть --}}
                {{-- Design: node uYnHt — gap 8 --}}
                {{-- Block = bg #FEE2E2 text #DC2626; Close = bg bg-input text-primary --}}
                <div class="flex" style="gap:8px;">
                    {{-- Блок / Разблокировать — toggles via banUser()/unbanUser() --}}
                    @php $isBanned = (bool) $activeBotUser->is_banned; @endphp
                    @if($isBanned)
                        <button
                            type="button"
                            wire:click="unbanUser"
                            wire:confirm="Разблокировать пользователя? Он снова сможет писать в поддержку."
                            wire:loading.attr="disabled"
                            wire:target="unbanUser"
                            class="flex items-center transition hover:opacity-90 disabled:opacity-50"
                            style="background:#DCFCE7; color:#16A34A; border-radius:8px; padding:6px 14px; font-size:12px; font-weight:500; gap:6px; border:none; cursor:pointer;"
                            aria-label="Разблокировать пользователя"
                            title="Разблокировать пользователя"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/>
                            </svg>
                            Разблокировать
                        </button>
                    @else
                        <button
                            type="button"
                            wire:click="banUser"
                            wire:confirm="Заблокировать пользователя? Его сообщения перестанут приниматься, а диалог будет закрыт."
                            wire:loading.attr="disabled"
                            wire:target="banUser"
                            class="flex items-center transition hover:opacity-90 disabled:opacity-50"
                            style="background:#FEE2E2; color:#DC2626; border-radius:8px; padding:6px 14px; font-size:12px; font-weight:500; gap:6px; border:none; cursor:pointer;"
                            aria-label="Заблокировать пользователя"
                            title="Блокировка пользователя"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <circle cx="12" cy="12" r="10"/><path d="m4.9 4.9 14.2 14.2"/>
                            </svg>
                            Блок
                        </button>
                    @endif

                    {{-- Закрыть button — runs the canonical CloseTopic flow via closeDialog() --}}
                    @php $isClosed = (bool) $activeBotUser->is_closed; @endphp
                    <button
                        type="button"
                        wire:click="closeDialog"
                        wire:confirm="Закрыть диалог? Пользователю придёт уведомление о закрытии и форма оценки."
                        wire:loading.attr="disabled"
                        wire:target="closeDialog"
                        @disabled($isClosed)
                        class="flex items-center transition hover:opacity-90 disabled:opacity-50 disabled:cursor-default"
                        style="background:#F1F3F5; color:#1A1D26; border-radius:8px; padding:6px 14px; font-size:12px; font-weight:500; gap:6px; border:none; cursor:pointer;"
                        aria-label="Закрыть диалог"
                        title="{{ $isClosed ? 'Диалог уже закрыт' : 'Закрыть тикет' }}"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/>
                        </svg>
                        {{ $isClosed ? 'Закрыто' : 'Закрыть' }}
                    </button>
                </div>
            </div>

            {{-- Divider --}}
            <div class="w-full h-px bg-border-light shrink-0"></div>

            {{-- ИНФОРМАЦИЯ section --}}
            {{-- Design: node RWpDk — vertical, gap 14 --}}
            <div class="flex flex-col w-full" style="gap:14px;">

                {{-- Section heading --}}
                {{-- Design: node a2tax7 — 12/600 text-secondary --}}
                <span class="text-text-secondary font-semibold tracking-wider" style="font-size:12px; letter-spacing:0.05em;">ИНФОРМАЦИЯ</span>

                {{-- Info rows — each: icon (16×16, text-secondary) + vertical texts (label 11 text-secondary / value 13 text-primary) --}}

                {{-- ID пользователя — hash icon --}}
                {{-- Design: node x2teWd — gap 10, align center --}}
                <div class="flex items-center w-full" style="gap:10px;">
                    <svg class="shrink-0 text-text-secondary" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <line x1="4" x2="20" y1="9" y2="9"/><line x1="4" x2="20" y1="15" y2="15"/><line x1="10" x2="8" y1="3" y2="21"/><line x1="16" x2="14" y1="3" y2="21"/>
                    </svg>
                    <div class="flex flex-col" style="gap:2px;">
                        <span class="text-text-secondary" style="font-size:11px;">ID пользователя</span>
                        <span class="text-text-primary font-mono" style="font-size:13px;">{{ $activeBotUser->chat_id }}</span>
                    </div>
                </div>

                {{-- Платформа — send icon --}}
                {{-- Design: node Kc4Ad --}}
                <div class="flex items-center w-full" style="gap:10px;">
                    <svg class="shrink-0 text-text-secondary" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>
                    </svg>
                    <div class="flex flex-col" style="gap:2px;">
                        <span class="text-text-secondary" style="font-size:11px;">Платформа</span>
                        <span class="text-text-primary" style="font-size:13px;">{{ $rpPlatformLabel }}</span>
                    </div>
                </div>

                {{-- Первое обращение — calendar icon --}}
                {{-- Design: node u4lnP --}}
                <div class="flex items-center w-full" style="gap:10px;">
                    <svg class="shrink-0 text-text-secondary" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/>
                    </svg>
                    <div class="flex flex-col" style="gap:2px;">
                        <span class="text-text-secondary" style="font-size:11px;">Первое обращение</span>
                        <span class="text-text-primary" style="font-size:13px;">
                            {{ $activeBotUser->created_at
                                ? \Carbon\Carbon::parse($activeBotUser->created_at)->locale('ru')->isoFormat('D MMMM YYYY')
                                : '—' }}
                        </span>
                    </div>
                </div>

            </div>

            {{-- Divider --}}
            {{-- Design: node GxLQl — #E5E7EB height 1 --}}
            <div class="w-full h-px shrink-0" style="background:#E5E7EB;"></div>

            {{-- МЕДИАФАЙЛЫ section --}}
            {{-- Design: node m1qYAD — vertical gap 12; Media Row: gap 8, thumbs 72×72 rounded-8 --}}
            @php $imageAttachments = $this->getImageAttachments() @endphp
            <div class="flex flex-col w-full" style="gap:12px;">

                {{-- Section heading --}}
                {{-- Design: node IUhIo — 12/600 #6B7280 letter-spacing 1 --}}
                <span class="font-semibold" style="font-size:12px; color:#6B7280; letter-spacing:0.07em;">МЕДИАФАЙЛЫ</span>

                {{-- Thumbnail grid --}}
                {{-- Design: node D0i1e — gap 8, row; thumbs 72×72 rounded-8 --}}
                @if($imageAttachments->isNotEmpty())
                    <div class="flex flex-wrap" style="gap:8px;">
                        @foreach($imageAttachments as $attachment)
                            @php
                                $fileUrl = $activeBotUser->platform === 'telegram'
                                    ? url('/api/files/' . $attachment->file_id)
                                    : $attachment->file_id;
                            @endphp
                            <img
                                src="{{ $fileUrl }}"
                                alt="{{ $attachment->file_type }}"
                                class="object-cover cursor-zoom-in hover:opacity-90 transition"
                                style="width:72px; height:72px; border-radius:8px; flex-shrink:0;"
                                loading="lazy"
                                x-on:click="$dispatch('open-lightbox', { src: '{{ $fileUrl }}' })"
                            >
                        @endforeach
                    </div>
                @else
                    <p class="text-text-secondary" style="font-size:13px;">Нет изображений</p>
                @endif
            </div>

        </aside>
    @endif

</div>

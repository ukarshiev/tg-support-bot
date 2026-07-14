<?php

declare(strict_types=1);

namespace App\Livewire\Chat;

use App\Jobs\TranslateMessageHistoryJob;
use App\Models\AiMessage;
use App\Models\AutoReply;
use App\Models\BotUser;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\MessageTranslation;
use App\Models\TranslationJob;
use App\Models\User;
use App\Modules\Admin\Actions\BanBotUser;
use App\Modules\Admin\Actions\ClearBotUserHistory;
use App\Modules\Admin\Actions\DeleteBotUser;
use App\Modules\Admin\Actions\SendReplyAction;
use App\Modules\Admin\Actions\UnbanBotUser;
use App\Modules\Ai\Actions\AiAcceptMessage;
use App\Modules\Ai\Actions\AiCancelMessage;
use App\Modules\Telegram\Actions\CloseTopic;
use App\Modules\Telegram\Services\ContactSummaryFormatter;
use App\Modules\Translation\DTOs\TranslationRequest;
use App\Modules\Translation\Services\SupportLanguageSettings;
use App\Modules\Translation\Services\TranslationService;
use App\Services\AutoReplies\AutoReplyVariableRenderer;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Full-screen manager chat workspace (standalone Livewire route).
 *
 * Accessible at GET /admin/chats (admin auth only).
 * Uses a minimal full-screen layout (no Filament chrome).
 *
 * Data / logic is identical to the old Filament ConversationPage — the same
 * $dialogList, $activeBotUser, $chatMessages, search, statusFilter, realtime events,
 * sendReply, insertQuickReply, shouldShowReplyForm, getMediaAttachments are kept.
 */
#[Layout('layouts.admin-chat')]
class ConversationPage extends Component
{
    use WithFileUploads;

    // ── Dialog list ────────────────────────────────────────────────────────────

    public string $search = '';

    public string $statusFilter = 'all';

    public ?int $activeBotUserId = null;

    public ?BotUser $activeBotUser = null;

    #[Locked]
    public Collection $dialogList;

    /** Number of dialogs loaded per page (the window grows on scroll-down). */
    private const DIALOG_PAGE = 30;

    /** Current dialog-list window size — grows as the manager scrolls down. */
    #[Locked]
    public int $dialogLimit = self::DIALOG_PAGE;

    /** Whether more dialogs remain below the loaded window. */
    public bool $hasMoreDialogs = false;

    // ── Chat area ──────────────────────────────────────────────────────────────

    #[Locked]
    public Collection $chatMessages;

    /**
     * Pending AI drafts for the active dialog (admin_panel mode only).
     *
     * @var \Illuminate\Support\Collection<int, AiMessage>
     */
    #[Locked]
    public Collection $pendingAiDrafts;

    /** Number of messages loaded per page (initial load + each scroll-up batch). */
    private const MESSAGES_PER_PAGE = 50;

    /**
     * Whether older messages remain to be loaded — drives the scroll-up loader
     * and the "load more" indicator at the top of the thread.
     */
    public bool $hasMoreMessages = false;

    public string $replyText = '';

    public ?string $replyTranslatedText = null;

    public string $replyTranslationStatus = 'empty';

    public ?string $replyTranslationError = null;

    public bool $autoAiEnabled = false;

    public ?string $autoAiNotice = null;

    public ?string $chatTranslationLocale = null;

    public bool $chatHistoryTranslationActive = false;

    public bool $chatHistoryTranslationHasPending = false;

    /**
     * Optional file to attach to the manager reply.
     *
     * Only honoured for platforms whose SendReplyAction branch handles files
     * (telegram, vk) — see supportsAttachments().
     *
     * @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null
     */
    public $attachment = null;

    /**
     * Highest message id seen so far — the watermark for desktop notifications.
     *
     * Set to the current max on mount so pre-existing history never notifies;
     * each poll notifies about incoming messages above this id (outside the open
     * dialog) and then advances it past everything. See notifyNewIncomingMessages().
     */
    #[Locked]
    public int $lastSeenMessageId = 0;

    // ── Lifecycle ──────────────────────────────────────────────────────────────

    /**
     * Initialise the workspace: empty collections, load dialog list.
     *
     * @return void
     */
    public function mount(): void
    {
        $this->dialogList = collect();
        $this->chatMessages = collect();
        $this->pendingAiDrafts = collect();
        $this->autoAiEnabled = (bool) (app(SettingsService::class)->get('ai.auto_reply') ?? false);
        $this->lastSeenMessageId = (int) Message::max('id');
        $this->loadDialogList();
    }

    public function toggleAutoAi(): void
    {
        $this->autoAiEnabled = ! $this->autoAiEnabled;
        app(SettingsService::class)->set('ai.auto_reply', $this->autoAiEnabled);

        $this->autoAiNotice = $this->autoAiEnabled
            ? 'Auto AI включён: AI отвечает клиентам сам.'
            : 'Auto AI выключен: AI пишет только внутренние подсказки.';
    }

    /**
     * Livewire polling interval.
     *
     * @return string|null
     */
    public function getPollingInterval(): ?string
    {
        return '30s';
    }

    // ── Dialog list ────────────────────────────────────────────────────────────

    /**
     * Load the dialog list, applying search and status filters.
     * Ordered by the most-recent message date (desc).
     *
     * Note: BotUser::messages() relation has swapped FK args in the model,
     * so withMax() cannot be used here. A raw correlated subquery is used
     * instead to avoid touching the model.
     *
     * @return void
     */
    public function loadDialogList(): void
    {
        // Load only the current window (one extra row to detect more below).
        // `unread_count` is a correlated subquery counting incoming messages that
        // arrived after the manager last read the dialog — drives the numeric
        // badge in the dialog list (see unreadCount()).
        $rows = BotUser::with(['lastMessage'])
            ->selectRaw(
                'bot_users.*, '
                . '(SELECT MAX(m.created_at) FROM messages m WHERE m.bot_user_id = bot_users.id) '
                . 'AS last_message_at, '
                . '(SELECT COUNT(*) FROM messages m WHERE m.bot_user_id = bot_users.id '
                . "AND m.message_type = 'incoming' "
                . 'AND (bot_users.manager_last_read_at IS NULL '
                . 'OR m.created_at > bot_users.manager_last_read_at)) AS unread_count'
            )
            ->when(
                $this->search !== '',
                fn ($q) => $q->where('chat_id', 'like', '%' . $this->search . '%')
            )
            ->when($this->statusFilter === 'open', fn ($q) => $q->where('is_closed', false))
            ->when($this->statusFilter === 'closed', fn ($q) => $q->where('is_closed', true))
            // Sort by the date of the most recent message (newest dialogs on top),
            // tie-broken by the newest message id — this matches the lastMessage
            // relation exactly, so the order never disagrees with the preview/time
            // shown for each dialog and stays stable across polls on same-second ties.
            ->orderByRaw(
                'COALESCE((SELECT MAX(m.created_at) FROM messages m WHERE m.bot_user_id = bot_users.id), \'1970-01-01\') DESC'
            )
            ->orderByRaw(
                'COALESCE((SELECT MAX(m.id) FROM messages m WHERE m.bot_user_id = bot_users.id), 0) DESC'
            )
            ->limit($this->dialogLimit + 1)
            ->get();

        $this->hasMoreDialogs = $rows->count() > $this->dialogLimit;
        $this->dialogList = $rows->take($this->dialogLimit)->values();
    }

    /**
     * Grow the dialog-list window by one page (triggered on scroll-down).
     *
     * @return void
     */
    public function loadMoreDialogs(): void
    {
        if (! $this->hasMoreDialogs) {
            return;
        }

        $this->dialogLimit += self::DIALOG_PAGE;
        $this->loadDialogList();
    }

    /**
     * Whether a dialog should show the "new message" indicator.
     *
     * True only when the user wrote the last message (incoming) in an open
     * conversation that is not banned, is not the one currently being viewed,
     * AND that message arrived after the manager last opened the dialog
     * (`manager_last_read_at`). The read timestamp makes the indicator survive
     * page reloads — opening a dialog marks it read (see selectChat()).
     *
     * @param BotUser $user
     *
     * @return bool
     */
    public function hasUnread(BotUser $user): bool
    {
        /** @var Message|null $last */
        $last = $user->lastMessage;

        if (
            $last?->message_type !== 'incoming'
            || $user->is_closed
            || $user->is_banned
            || $this->activeBotUserId === $user->id
        ) {
            return false;
        }

        $readAt = $user->manager_last_read_at;

        return $readAt === null || $last->created_at?->gt($readAt);
    }

    /**
     * Number of unread (new) incoming messages for a dialog's badge.
     *
     * Returns 0 whenever the dialog should not be flagged (see hasUnread() for
     * the gating rules); otherwise counts incoming messages that arrived after
     * `manager_last_read_at` (all incoming when never read). Prefers the
     * `unread_count` value pre-computed by loadDialogList() and falls back to a
     * direct count for users loaded without it (e.g. in tests).
     *
     * @param BotUser $user
     *
     * @return int
     */
    public function unreadCount(BotUser $user): int
    {
        if (! $this->hasUnread($user)) {
            return 0;
        }

        $count = $user->unread_count
            ?? Message::where('bot_user_id', $user->id)
                ->where('message_type', 'incoming')
                ->when(
                    $user->manager_last_read_at !== null,
                    fn ($q) => $q->where('created_at', '>', $user->manager_last_read_at)
                )
                ->count();

        return (int) $count;
    }

    /**
     * Triggered when the search field changes (wire:model.live.debounce).
     *
     * @return void
     */
    public function updatedSearch(): void
    {
        $this->dialogLimit = self::DIALOG_PAGE;
        $this->loadDialogList();
    }

    /**
     * Triggered when the status-filter tab changes.
     *
     * @return void
     */
    public function updatedStatusFilter(): void
    {
        $this->dialogLimit = self::DIALOG_PAGE;
        $this->loadDialogList();
    }

    /**
     * Select a dialog and load its messages.
     *
     * @param int $botUserId
     *
     * @return void
     */
    public function selectChat(int $botUserId): void
    {
        if ($botUserId === 0) {
            $this->activeBotUserId = null;
            $this->activeBotUser = null;
            $this->chatMessages = collect();
            $this->resetComposerState();
            $this->resetChatTranslationState();

            return;
        }

        $this->resetComposerState();
        $this->resetChatTranslationState();
        $this->activeBotUserId = $botUserId;
        $this->activeBotUser = BotUser::with(['externalUser'])->find($botUserId);

        // Opening a dialog marks ALL of its current messages read — the indicator
        // stays cleared across page reloads (persisted, not just session state).
        if ($this->activeBotUser) {
            $this->markConversationRead($this->activeBotUser);
        }

        $this->loadMessages();
        $this->loadPendingAiDrafts();
        $this->syncChatTranslationState();
        $this->queueVisibleHistoryTranslations();
        $this->reloadLoadedMessages();
        $this->loadDialogList();

        // Always scroll to the bottom when opening a dialog.
        $this->dispatch('messages-updated');
        $this->dispatch('chat-selected', botUserId: $botUserId);
    }

    private function resetComposerState(): void
    {
        $this->replyText = '';
        $this->replyTranslatedText = null;
        $this->replyTranslationStatus = 'empty';
        $this->replyTranslationError = null;
        $this->attachment = null;
        $this->resetValidation(['replyText', 'attachment']);
    }

    private function resetChatTranslationState(): void
    {
        $this->chatTranslationLocale = null;
        $this->chatHistoryTranslationActive = false;
        $this->chatHistoryTranslationHasPending = false;
    }

    /**
     * Mark a conversation fully read for the manager.
     *
     * Sets `manager_last_read_at` to the later of now() and the newest message's
     * `created_at`, so every message that currently exists in the dialog counts
     * as read. Messages are persisted by queued jobs, so a message that arrived
     * before the manager opened the dialog can get a `created_at` slightly ahead
     * of the wall clock (the job ran after the click). Marking read with a bare
     * now() would leave such a message counted as unread (the badge "snaps" to 1
     * right after opening); snapping the read marker up to the newest message's
     * timestamp avoids that.
     *
     * @param BotUser $user
     *
     * @return void
     */
    private function markConversationRead(BotUser $user): void
    {
        $readAt = now();

        $latestAt = Message::where('bot_user_id', $user->id)->max('created_at');

        if ($latestAt !== null) {
            $latestAt = Carbon::parse($latestAt);

            if ($latestAt->greaterThan($readAt)) {
                $readAt = $latestAt;
            }
        }

        $user->update(['manager_last_read_at' => $readAt]);
    }

    /**
     * Reconciliation poll entry point: refresh the dialog list and,
     * when a dialog is open, the message thread too.
     *
     * Only scrolls to the bottom and bumps the read marker when new messages
     * actually arrived — so a manager scrolled up reading history is not yanked
     * down on every tick.
     *
     * @return void
     */
    public function pollUpdates(): void
    {
        $this->loadDialogList();

        // Raise a desktop notification for messages that landed in other dialogs
        // since the last tick (runs regardless of whether a dialog is open).
        $this->notifyNewIncomingMessages();

        if (! $this->activeBotUser) {
            return;
        }

        // Append only messages newer than the last loaded one — this preserves
        // any older history the manager scrolled up to load.
        $added = $this->loadNewerMessages();
        $this->loadPendingAiDrafts();
        if ($this->chatHistoryTranslationHasPending) {
            $this->reloadLoadedMessages();
        }

        if ($added > 0) {
            $this->markConversationRead($this->activeBotUser);
            $this->dispatch('messages-updated');
        }

        // Livewire polling morphs the chat DOM every few seconds. The textarea
        // height is calculated on the client, so ask Alpine to restore autosize
        // after each poll response and keep the draft field from collapsing.
        $this->dispatch('chat-input-autosize');
    }

    #[On('support-message-committed')]
    public function handleRealtimeMessage(int $messageId, int $conversationId, string $traceId = ''): void
    {
        if ($messageId <= 0 || $conversationId <= 0) {
            return;
        }

        $this->pollUpdates();
    }

    private function reloadLoadedMessages(): void
    {
        $ids = $this->chatMessages->pluck('id')->all();
        if ($ids === []) {
            return;
        }

        $messages = Message::whereIn('id', $ids)
            ->with(['externalMessage', 'attachments', 'sender', 'translations'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $this->chatMessages = $messages->values();
        $this->refreshHistoryTranslationPendingState();
    }

    /**
     * Emit a browser event for incoming messages that arrived since the last
     * poll, so the client can raise a desktop notification (Web Notifications).
     *
     * Considers only `incoming` messages above the `lastSeenMessageId` watermark,
     * excluding the currently open dialog (the operator is already reading it)
     * and banned users. The watermark is then advanced past every message — incl.
     * the open dialog's and outgoing rows — so each message notifies exactly once.
     *
     * @return void
     */
    private function notifyNewIncomingMessages(): void
    {
        $query = Message::query()
            ->join('bot_users', 'bot_users.id', '=', 'messages.bot_user_id')
            ->where('messages.message_type', 'incoming')
            ->where('messages.id', '>', $this->lastSeenMessageId)
            ->where('bot_users.is_banned', false);

        if ($this->activeBotUserId !== null) {
            $query->where('messages.bot_user_id', '!=', $this->activeBotUserId);
        }

        $fresh = $query->orderBy('messages.id')
            ->get(['messages.id', 'messages.text', 'messages.bot_user_id']);

        // Advance the watermark past ALL messages so nothing re-notifies.
        $this->lastSeenMessageId = max($this->lastSeenMessageId, (int) Message::max('id'));

        if ($fresh->isEmpty()) {
            return;
        }

        /** @var Message $latest */
        $latest = $fresh->last();
        $count = $fresh->count();

        $notifyUser = BotUser::where('id', $latest->bot_user_id)->first(['chat_id', 'display_name']);
        $chatId = $notifyUser !== null
            ? (string) ($notifyUser->display_name ?? $notifyUser->chat_id)
            : '';
        $preview = filled($latest->text)
            ? mb_substr((string) $latest->text, 0, 80)
            : 'Вложение';

        $this->dispatch(
            'new-incoming-messages',
            count: $count,
            title: $count > 1 ? "Новые сообщения ({$count})" : 'Новое сообщение',
            body: "Чат {$chatId}: {$preview}",
        );
    }

    // ── Chat area ──────────────────────────────────────────────────────────────

    /**
     * Load messages for the active conversation.
     *
     * Pure loader — callers decide when to scroll the thread (via the
     * `messages-updated` browser event) so polling does not force-scroll.
     *
     * @return void
     */
    public function loadMessages(): void
    {
        if (! $this->activeBotUser) {
            $this->chatMessages = collect();
            $this->hasMoreMessages = false;

            return;
        }

        // Most recent page only — older messages are pulled in on scroll-up.
        // Fetch one extra row to detect whether more history exists.
        $batch = Message::where('bot_user_id', $this->activeBotUserId)
            ->with(['externalMessage', 'attachments', 'sender', 'translations'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::MESSAGES_PER_PAGE + 1)
            ->get();

        $this->hasMoreMessages = $batch->count() > self::MESSAGES_PER_PAGE;

        $this->chatMessages = $batch->take(self::MESSAGES_PER_PAGE)
            ->reverse()
            ->values();

        $this->refreshHistoryTranslationPendingState();
    }

    /**
     * Prepend the previous page of older messages (triggered on scroll-up).
     *
     * Uses a (created_at, id) keyset cursor on the oldest currently-loaded
     * message, so paging is stable and does not re-scan the whole thread.
     *
     * @return void
     */
    public function loadOlderMessages(): void
    {
        if (! $this->activeBotUserId || ! $this->hasMoreMessages || $this->chatMessages->isEmpty()) {
            return;
        }

        /** @var Message $oldest */
        $oldest = $this->chatMessages->first();

        $batch = Message::where('bot_user_id', $this->activeBotUserId)
            ->with(['externalMessage', 'attachments', 'sender', 'translations'])
            ->where(function ($q) use ($oldest): void {
                $q->where('created_at', '<', $oldest->created_at)
                    ->orWhere(function ($q2) use ($oldest): void {
                        $q2->where('created_at', $oldest->created_at)
                            ->where('id', '<', $oldest->id);
                    });
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::MESSAGES_PER_PAGE + 1)
            ->get();

        $this->hasMoreMessages = $batch->count() > self::MESSAGES_PER_PAGE;

        $older = $batch->take(self::MESSAGES_PER_PAGE)
            ->reverse()
            ->values();

        $this->chatMessages = $older->concat($this->chatMessages)->values();
        $this->queueVisibleHistoryTranslations($older);
        $this->reloadLoadedMessages();
    }

    /**
     * Append messages newer than the last loaded one (used by polling so the
     * scrolled-up history window is preserved, only fresh messages are added).
     *
     * @return int Number of new messages appended.
     */
    private function loadNewerMessages(): int
    {
        if (! $this->activeBotUserId) {
            return 0;
        }

        /** @var Message|null $newest */
        $newest = $this->chatMessages->last();

        $query = Message::where('bot_user_id', $this->activeBotUserId)
            ->with(['externalMessage', 'attachments', 'sender', 'translations'])
            ->orderBy('created_at')
            ->orderBy('id');

        if ($newest) {
            $query->where(function ($q) use ($newest): void {
                $q->where('created_at', '>', $newest->created_at)
                    ->orWhere(function ($q2) use ($newest): void {
                        $q2->where('created_at', $newest->created_at)
                            ->where('id', '>', $newest->id);
                    });
            });
        }

        $new = $query->get();

        if ($new->isNotEmpty()) {
            $this->chatMessages = $this->chatMessages->concat($new)->values();
            $this->queueVisibleHistoryTranslations($new);
        }

        return $new->count();
    }

    /**
     * Send a manager reply (text and/or a file attachment) to the active bot user.
     *
     * Text is required only when no file is attached, so a file-only message is
     * allowed. The attachment is ignored for platforms that cannot deliver files
     * (see supportsAttachments()).
     *
     * @return void
     */
    public function sendReply(): void
    {
        if (! $this->shouldShowReplyForm() || empty($this->activeBotUser)) {
            return;
        }

        $file = $this->supportsAttachments() ? $this->attachment : null;

        // Nothing to send (no text and no attachment) — silently ignore instead
        // of surfacing a "required" validation error.
        if (trim($this->replyText) === '' && $file === null) {
            return;
        }

        // Emptiness is handled above; only validate size/length here.
        $this->validate([
            'replyText' => ['nullable', 'string', 'max:4096'],
            'attachment' => ['nullable', 'file', 'max:20480'],
        ]);

        $targetLocale = strtolower(trim((string) $this->activeBotUser->preferred_language_code));
        if (trim($this->replyText) !== '' && $targetLocale !== 'ru') {
            if ($targetLocale === '') {
                $this->toast('Клиент ещё не выбрал язык. Текст не отправлен.', 'error');

                return;
            }

            if ($this->replyTranslationStatus !== 'ready' || trim((string) $this->replyTranslatedText) === '') {
                $this->toast('Перевод ещё не готов. Текст не отправлен.', 'error');

                return;
            }
        }

        $sourceText = $this->replyText;
        $textToSend = app(AutoReplyVariableRenderer::class)->render(
            $this->replyTranslatedText ?: $this->replyText,
            $this->activeBotUser,
        )[0];

        /** @var \App\Models\User $operator */
        $operator = Auth::user();
        $message = SendReplyAction::execute($this->activeBotUser, $textToSend, $file, $operator);

        if (trim($sourceText) !== '' && $textToSend !== $sourceText) {
            MessageTranslation::create([
                'message_id' => $message->id,
                'source_locale' => 'ru',
                'target_locale' => $this->activeBotUser->preferred_language_code,
                'source_text' => $sourceText,
                'translated_text' => $textToSend,
                'direction' => 'operator_to_client',
                'status' => 'ready',
                'source' => 'auto',
                'provider' => 'translation_core',
                'source_hash' => hash('sha256', trim($sourceText)),
                'translated_at' => now(),
            ]);
        }

        $this->replyText = '';
        $this->replyTranslatedText = null;
        $this->replyTranslationStatus = 'empty';
        $this->replyTranslationError = null;
        $this->attachment = null;
        $this->loadMessages();
        $this->loadDialogList();
        $this->dispatch('messages-updated');

        $this->toast('Сообщение отправлено');
    }

    /**
     * Whether file attachments can be delivered for the active dialog.
     *
     * telegram, vk and max have a file-aware branch in SendReplyAction;
     * external replies are text-only, so the attach control is hidden there.
     *
     * @return bool
     */
    public function supportsAttachments(): bool
    {
        return in_array($this->activeBotUser?->platform, ['telegram', 'vk', 'max'], true);
    }

    /**
     * Public profile URL for the active user, or null when none can be built.
     *
     * Telegram requires a public username. VK can be opened by numeric id.
     *
     * @return string|null
     */
    public function profileUrl(): ?string
    {
        $user = $this->activeBotUser;

        if ($user === null) {
            return null;
        }

        return match ($user->platform) {
            'telegram' => $user->username
                ? 'https://telegram.me/' . ltrim((string) $user->username, '@')
                : null,
            'vk' => ctype_digit(trim((string) $user->chat_id))
                ? 'https://vk.com/id' . trim((string) $user->chat_id)
                : null,
            default => null,
        };
    }

    /**
     * Rows for the contact details drawer.
     *
     * Only already stored data is used here. We intentionally do not call Telegram
     * getChat while opening the drawer, so the operator UI stays fast.
     *
     * @return array<int, array{label: string, value: string, url?: string}>
     */
    public function contactDetails(): array
    {
        $user = $this->activeBotUser;

        if ($user === null) {
            return [];
        }

        $platformLabel = match ($user->platform) {
            'telegram' => 'Telegram',
            'vk' => 'VK',
            'max' => 'Max',
            default => ucfirst((string) $user->platform),
        };

        $rows = [
            ['label' => 'Платформа', 'value' => $platformLabel],
            ...app(ContactSummaryFormatter::class)->rows($user),
            ['label' => 'Статус диалога', 'value' => $user->is_closed ? 'закрыт' : 'открыт'],
            ['label' => 'Статус блокировки', 'value' => $user->is_banned ? 'заблокирован' : 'активен'],
            ['label' => 'Topic ID', 'value' => $user->topic_id ? (string) $user->topic_id : 'не назначен'],
        ];

        if ($user->preferred_language_selected_at !== null) {
            $rows[] = [
                'label' => 'Язык выбран',
                'value' => $this->formatContactDate($user->preferred_language_selected_at),
            ];
        }

        if ($user->profile_synced_at !== null) {
            $rows[] = [
                'label' => 'Профиль обновлён',
                'value' => $this->formatContactDate($user->profile_synced_at),
            ];
        }

        if ($user->externalUser !== null) {
            $rows[] = ['label' => 'Внешний источник', 'value' => (string) $user->externalUser->source];
            $rows[] = ['label' => 'Внешний ID', 'value' => (string) $user->externalUser->external_id];
        }

        return $rows;
    }

    public function contactSummaryText(): string
    {
        if ($this->activeBotUser === null) {
            return '';
        }

        return app(ContactSummaryFormatter::class)->toPlainText($this->activeBotUser);
    }

    /**
     * @param mixed $date
     *
     * @return string
     */
    private function formatContactDate(mixed $date): string
    {
        return $date ? Carbon::parse($date)->format('d.m.Y H:i') : 'неизвестно';
    }

    /**
     * Discard the currently selected attachment.
     *
     * @return void
     */
    public function removeAttachment(): void
    {
        $this->attachment = null;
        $this->resetValidation('attachment');
    }

    /**
     * Close the active conversation via the canonical CloseTopic flow:
     * notifies the user, closes the Telegram forum topic (when present),
     * marks the BotUser as closed, and triggers the feedback form.
     *
     * No-op when there is no active dialog or it is already closed.
     *
     * @return void
     */
    public function closeDialog(): void
    {
        if (empty($this->activeBotUser) || $this->activeBotUser->isClosed()) {
            return;
        }

        app(CloseTopic::class)->execute($this->activeBotUser);

        $this->activeBotUser->refresh();
        $this->loadMessages();
        $this->loadDialogList();

        $this->toast('Диалог закрыт');
    }

    /**
     * Emit a transient success toast to the browser.
     *
     * Dispatches the `admin-toast` event consumed by the Alpine listener in
     * the admin-chat layout (replaces the former Filament notifications).
     *
     * @param string $message
     * @param string $type
     *
     * @return void
     */
    private function toast(string $message, string $type = 'success'): void
    {
        $this->dispatch('admin-toast', message: $message, type: $type);
    }

    /**
     * Whether the current user is an administrator.
     *
     * Used to gate destructive actions (chat deletion) — only admins may
     * delete a conversation; managers must not see or trigger the button.
     *
     * @return bool
     */
    public function isAdmin(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->isAdmin();
    }

    /**
     * Permanently delete the active conversation and all of its messages.
     *
     * Removes the BotUser plus its messages, attachments, AI messages, AI
     * condition flags and feedback (see DeleteBotUser), then clears the active
     * dialog and refreshes the list. No-op when there is no active dialog.
     *
     * @return void
     */
    public function deleteChat(): void
    {
        if (! $this->isAdmin()) {
            abort(403);
        }

        if (empty($this->activeBotUser)) {
            return;
        }

        app(DeleteBotUser::class)->execute($this->activeBotUser);

        $this->activeBotUserId = null;
        $this->activeBotUser = null;
        $this->chatMessages = collect();
        $this->loadDialogList();

        $this->toast('Чат удалён');
    }

    /**
     * Clear the active conversation's message history, keeping the chat itself.
     *
     * Deletes the dialog's messages (and attachments / external rows) and AI
     * messages via ClearBotUserHistory, then reloads the now-empty thread and
     * refreshes the list preview. The BotUser stays. No-op without an active dialog.
     *
     * @return void
     */
    public function clearHistory(): void
    {
        if (empty($this->activeBotUser)) {
            return;
        }

        app(ClearBotUserHistory::class)->execute($this->activeBotUser);

        $this->loadMessages();
        $this->loadDialogList();
        $this->dispatch('messages-updated');

        $this->toast('История очищена');
    }

    /**
     * Ban the active bot user (mark banned + closed, close the Telegram topic).
     *
     * No-op when there is no active dialog or it is already banned.
     *
     * @return void
     */
    public function banUser(): void
    {
        if (empty($this->activeBotUser) || $this->activeBotUser->isBanned()) {
            return;
        }

        app(BanBotUser::class)->execute($this->activeBotUser);

        $this->activeBotUser->refresh();
        $this->loadMessages();
        $this->loadDialogList();

        $this->toast('Пользователь заблокирован');
    }

    /**
     * Lift the ban from the active bot user.
     *
     * No-op when there is no active dialog or the user is not banned.
     *
     * @return void
     */
    public function unbanUser(): void
    {
        if (empty($this->activeBotUser) || ! $this->activeBotUser->isBanned()) {
            return;
        }

        app(UnbanBotUser::class)->execute($this->activeBotUser);

        $this->activeBotUser->refresh();
        $this->loadMessages();
        $this->loadDialogList();

        $this->toast('Пользователь разблокирован');
    }

    /**
     * Whether the reply form should be rendered in the chat workspace.
     *
     * Always true: SendReplyAction routes the reply by BotUser platform
     * (telegram/vk/external), so the manager can always reply from the workspace
     * (the admin panel is an always-active surface).
     *
     * @return bool
     */
    public function shouldShowReplyForm(): bool
    {
        return true;
    }

    // ── Quick replies ──────────────────────────────────────────────────────────

    /**
     * Insert a quick-reply template into the reply text field.
     *
     * @param string $text
     *
     * @return void
     */
    public function insertQuickReply(string $text): void
    {
        $this->replyText = $text;
        $this->refreshReplyTranslation();
    }

    public function updatedReplyText(): void
    {
        if (mb_strlen(trim($this->replyText)) < 3) {
            $this->replyTranslatedText = null;
            $this->replyTranslationStatus = 'empty';
            $this->replyTranslationError = null;
            return;
        }

        $this->refreshReplyTranslation();
    }

    public function refreshReplyTranslation(): void
    {
        if (!$this->activeBotUser || trim($this->replyText) === '') {
            return;
        }

        $targetLocale = $this->activeBotUser->preferred_language_code;
        if ($targetLocale === null || $targetLocale === '') {
            $this->replyTranslationStatus = 'language_not_selected';
            $this->replyTranslatedText = null;
            $this->replyTranslationError = 'Клиент ещё не выбрал язык.';
            return;
        }

        if ($targetLocale === 'ru') {
            $this->replyTranslationStatus = 'ready';
            $this->replyTranslatedText = $this->replyText;
            $this->replyTranslationError = null;
            return;
        }

        $this->replyTranslationStatus = 'translating';
        $result = app(TranslationService::class)->translate(new TranslationRequest(
            sourceLocale: 'ru',
            targetLocale: $targetLocale,
            text: $this->replyText,
            purpose: 'operator_reply',
        ));

        if ($result->success) {
            $this->replyTranslationStatus = 'ready';
            $this->replyTranslatedText = $result->text;
            $this->replyTranslationError = null;
            return;
        }

        $this->replyTranslationStatus = 'error';
        $this->replyTranslatedText = null;
        $this->replyTranslationError = $result->errorMessage ?? 'Перевод не выполнен.';
    }

    /**
     * Языки для dropdown переводчика диалога.
     *
     * @return array<int, array{code: string, label: string, tooltip: string}>
     */
    public function availableTranslationLanguages(): array
    {
        $languages = app(SupportLanguageSettings::class)->enabledLanguages();

        return collect($languages)
            ->map(fn (array $language): array => [
                'code' => (string) $language['code'],
                'label' => $this->languageFlag((string) $language['code']) . ' ' . mb_strtoupper((string) $language['code']),
                'tooltip' => (string) $language['name'],
            ])
            ->values()
            ->all();
    }

    public function chatTranslationButtonLabel(): string
    {
        if ($this->chatTranslationLocale === null || $this->chatTranslationLocale === '') {
            return 'Не выбран';
        }

        return $this->languageFlag($this->chatTranslationLocale) . ' ' . mb_strtoupper($this->chatTranslationLocale);
    }

    public function chatTranslationTooltip(): string
    {
        if ($this->chatTranslationLocale === null || $this->chatTranslationLocale === '') {
            return 'Язык клиента не выбран';
        }

        if ($this->chatTranslationLocale === 'ru') {
            return 'Русский язык — перевод не требуется';
        }

        return 'Перевод диалога включён';
    }

    public function setChatTranslationLocale(string $locale): void
    {
        if (!$this->activeBotUser) {
            return;
        }

        $enabled = collect($this->availableTranslationLanguages())->pluck('code')->all();
        if (!in_array($locale, $enabled, true)) {
            return;
        }

        $this->activeBotUser->update([
            'chat_translation_locale' => $locale,
            'chat_translation_locale_selected_at' => now(),
        ]);
        $this->activeBotUser->refresh();
        $this->syncChatTranslationState();
        $this->queueVisibleHistoryTranslations();
        $this->loadMessages();
        $this->toast($locale === 'ru' ? 'Перевод истории выключен' : 'Перевод истории запущен');
    }

    public function retryMessageTranslation(int $messageId): void
    {
        if (!$this->activeBotUser || !$this->chatHistoryTranslationActive) {
            return;
        }

        $message = Message::where('id', $messageId)
            ->where('bot_user_id', $this->activeBotUser->id)
            ->first();

        if ($message === null) {
            return;
        }

        $this->queueTranslationForMessage($message, force: true);
        $this->loadMessages();
        $this->toast('Повторный перевод поставлен в очередь');
    }

    private function syncChatTranslationState(): void
    {
        if (!$this->activeBotUser) {
            $this->chatTranslationLocale = null;
            $this->chatHistoryTranslationActive = false;
            $this->chatHistoryTranslationHasPending = false;

            return;
        }

        $enabled = collect($this->availableTranslationLanguages())->pluck('code')->all();
        $locale = $this->resolveChatTranslationLocale($this->activeBotUser);

        if ($locale !== null && !in_array($locale, $enabled, true)) {
            $locale = null;
        }

        if ($locale !== null && $this->activeBotUser->chat_translation_locale !== $locale) {
            $this->activeBotUser->forceFill([
                'chat_translation_locale' => $locale,
                'chat_translation_locale_selected_at' => $this->activeBotUser->chat_translation_locale_selected_at ?: now(),
            ])->save();
            $this->activeBotUser->refresh();
        }

        $this->chatTranslationLocale = $locale;
        $this->chatHistoryTranslationActive = $locale !== null && $locale !== 'ru';
        $this->refreshHistoryTranslationPendingState();
    }

    private function resolveChatTranslationLocale(BotUser $botUser): ?string
    {
        $chatLocale = $botUser->chat_translation_locale;

        if (
            $chatLocale !== null
            && $chatLocale !== ''
            && $botUser->chat_translation_locale_selected_at !== null
            && (
                $botUser->preferred_language_selected_at === null
                || $botUser->chat_translation_locale_selected_at->greaterThanOrEqualTo($botUser->preferred_language_selected_at)
            )
        ) {
            return $chatLocale;
        }

        if ($botUser->preferred_language_selected_at !== null && filled($botUser->preferred_language_code)) {
            return $botUser->preferred_language_code;
        }

        return null;
    }

    private function queueVisibleHistoryTranslations(?Collection $messages = null): void
    {
        if (!$this->activeBotUser || !$this->chatHistoryTranslationActive) {
            $this->refreshHistoryTranslationPendingState();

            return;
        }

        ($messages ?? $this->chatMessages)
            ->reject(fn (Message $message): bool => $this->isLanguageSelectorMessage($message))
            ->filter(fn (Message $message): bool => trim((string) ($message->text ?? $message->externalMessage?->text)) !== '')
            ->take(-self::MESSAGES_PER_PAGE)
            ->each(function (Message $message): void {
                $this->queueTranslationForMessage($message);
            });

        $this->refreshHistoryTranslationPendingState();
    }

    public function shouldHideMessageFromHistory(Message $message): bool
    {
        return $message->message_kind === Message::KIND_LANGUAGE_SELECTOR
            || app(\App\Modules\Telegram\Services\SupportLanguageService::class)
                ->isSelectorText($message->text ?? $message->externalMessage?->text);
    }

    private function isLanguageSelectorMessage(Message $message): bool
    {
        return $this->shouldHideMessageFromHistory($message);
    }

    private function queueTranslationForMessage(Message $message, bool $force = false): void
    {
        $locale = $this->chatTranslationLocale ?: 'ru';
        if ($locale === 'ru') {
            return;
        }

        $sourceText = trim((string) ($message->text ?? $message->externalMessage?->text));
        if ($sourceText === '') {
            return;
        }

        $direction = $message->message_type === 'incoming' ? 'client_to_operator' : 'system_to_operator';
        $sourceLocale = $locale;
        $targetLocale = 'ru';

        /** @var MessageTranslation|null $existing */
        $existing = $message->translations()
            ->where('direction', $direction)
            ->where('source_locale', $sourceLocale)
            ->where('target_locale', $targetLocale)
            ->where('source_hash', TranslationService::sourceHash($sourceText))
            ->first();

        if ($existing !== null && !$force && in_array($existing->status, ['queued', 'running', 'ready'], true)) {
            return;
        }

        $messageTranslation = MessageTranslation::updateOrCreate(
            [
                'message_id' => $message->id,
                'direction' => $direction,
                'source_locale' => $sourceLocale,
                'target_locale' => $targetLocale,
                'source_hash' => TranslationService::sourceHash($sourceText),
            ],
            [
                'source_text' => $sourceText,
                'translated_text' => $force ? null : ($existing?->translated_text),
                'status' => 'queued',
                'source' => 'auto',
                'provider' => null,
                'error_message' => null,
            ]
        );

        $monitor = TranslationJob::create([
            'job_type' => TranslationJob::TYPE_MESSAGE_HISTORY,
            'subject_type' => Message::class,
            'subject_id' => $message->id,
            'subject_label' => 'Сообщение #' . $message->id,
            'source_locale' => $sourceLocale,
            'target_locale' => $targetLocale,
            'status' => TranslationJob::STATUS_QUEUED,
            'characters' => mb_strlen($sourceText),
            'queued_at' => now(),
            'meta' => [
                'bot_user_id' => $message->bot_user_id,
                'direction' => $direction,
                'message_translation_id' => $messageTranslation->id,
            ],
        ]);

        TranslateMessageHistoryJob::dispatch($message->id, $messageTranslation->id, $monitor->id);
    }

    private function refreshHistoryTranslationPendingState(): void
    {
        $ids = $this->chatMessages->pluck('id')->filter()->all();
        if ($ids === []) {
            $this->chatHistoryTranslationHasPending = false;

            return;
        }

        $this->chatHistoryTranslationHasPending = MessageTranslation::whereIn('message_id', $ids)
            ->whereIn('status', ['queued', 'running'])
            ->exists();
    }

    private function languageFlag(string $code): string
    {
        return [
            'ru' => '🇷🇺',
            'en' => '🇺🇸',
            'uk' => '🇺🇦',
            'it' => '🇮🇹',
            'de' => '🇩🇪',
            'es' => '🇪🇸',
            'pl' => '🇵🇱',
            'ro' => '🇷🇴',
            'fr' => '🇫🇷',
            'tg' => '🇹🇯',
            'az' => '🇦🇿',
            'tr' => '🇹🇷',
            'kk' => '🇰🇿',
            'uz' => '🇺🇿',
        ][$code] ?? '🌐';
    }

    // ── Media gallery ──────────────────────────────────────────────────────────

    /**
     * Return all media attachments (photos, documents, video, audio, …) for the active dialog.
     *
     * @return \Illuminate\Support\Collection<int, MessageAttachment>
     */
    public function getMediaAttachments(): Collection
    {
        if (! $this->activeBotUser) {
            return collect();
        }

        return MessageAttachment::whereIn(
            'message_id',
            Message::where('bot_user_id', $this->activeBotUserId)->pluck('id')
        )->get();
    }

    /**
     * Active auto-reply rules, offered as quick-insert chips above the reply input.
     *
     * @return \Illuminate\Support\Collection<int, AutoReply>
     */
    public function getAutoReplies(): Collection
    {
        return AutoReply::where('type', AutoReply::TYPE_REGULAR)
            ->where('enabled', true)
            ->orderBy('id')
            ->get();
    }

    /**
     * Load pending AI drafts for the active dialog.
     *
     * Drafts are always shown in the admin panel workspace regardless of
     * whether the Telegram supergroup is configured.
     *
     * @return void
     */
    private function loadPendingAiDrafts(): void
    {
        if (! $this->activeBotUserId) {
            $this->pendingAiDrafts = collect();

            return;
        }

        $this->pendingAiDrafts = AiMessage::where('bot_user_id', $this->activeBotUserId)
            ->where('status', AiMessage::STATUS_PENDING)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $this->pendingAiDrafts->each(function (AiMessage $draft): void {
            $this->ensureAiDraftOperatorTextIsRussian($draft);
        });
    }

    private function ensureAiDraftOperatorTextIsRussian(AiMessage $draft): void
    {
        $targetLocale = (string) ($draft->target_locale ?: $this->chatTranslationLocale ?: $this->activeBotUser?->preferred_language_code ?: 'ru');
        if ($targetLocale === 'ru') {
            return;
        }

        $operatorText = trim((string) ($draft->text_source ?: ''));
        $clientText = trim((string) ($draft->text_translated ?: $draft->text_ai ?: ''));
        if ($clientText === '') {
            return;
        }

        $sourceLooksLikeClientText = $operatorText === ''
            || $operatorText === trim((string) $draft->text_translated)
            || $operatorText === trim((string) $draft->text_ai);

        if (!$sourceLooksLikeClientText) {
            return;
        }

        $result = app(TranslationService::class)->translate(new TranslationRequest(
            sourceLocale: $targetLocale,
            targetLocale: 'ru',
            text: $clientText,
            purpose: 'ai_draft_operator_preview',
        ));

        if (!$result->success || !is_string($result->text) || trim($result->text) === '') {
            return;
        }

        $draft->forceFill([
            'text_source' => $result->text,
            'source_locale' => 'ru',
            'translation_provider' => $draft->translation_provider ?: $result->provider,
            'translation_status' => $draft->translation_status ?: 'ready',
            'translated_at' => $draft->translated_at ?: now(),
        ])->save();
    }

    /**
     * Accept a pending AI draft: deliver to user and mark as accepted.
     *
     * @param int $aiMessageId AiMessage primary key
     *
     * @return void
     */
    public function acceptAiDraft(int $aiMessageId): void
    {
        if (! $this->activeBotUserId) {
            return;
        }

        $draft = AiMessage::where('id', $aiMessageId)
            ->where('bot_user_id', $this->activeBotUserId)
            ->where('status', AiMessage::STATUS_PENDING)
            ->first();

        if ($draft === null) {
            return;
        }

        app(AiAcceptMessage::class)->executeForDraft($draft);

        $this->loadPendingAiDrafts();
        $this->loadMessages();
        $this->loadDialogList();
        $this->dispatch('messages-updated');

        $this->toast('ИИ-ответ отправлен');
    }

    /**
     * Cancel a pending AI draft: mark as cancelled, remove from workspace.
     *
     * @param int $aiMessageId AiMessage primary key
     *
     * @return void
     */
    public function cancelAiDraft(int $aiMessageId): void
    {
        if (! $this->activeBotUserId) {
            return;
        }

        $draft = AiMessage::where('id', $aiMessageId)
            ->where('bot_user_id', $this->activeBotUserId)
            ->where('status', AiMessage::STATUS_PENDING)
            ->first();

        if ($draft === null) {
            return;
        }

        app(AiCancelMessage::class)->executeForDraft($draft);

        $this->loadPendingAiDrafts();
    }

    /**
     * Edit a pending AI draft: copy text_ai into the reply input and cancel the draft.
     *
     * The operator can then edit the text freely and send it as a normal reply.
     *
     * @param int $aiMessageId AiMessage primary key
     *
     * @return void
     */
    public function editAiDraft(int $aiMessageId): void
    {
        if (! $this->activeBotUserId) {
            return;
        }

        $draft = AiMessage::where('id', $aiMessageId)
            ->where('bot_user_id', $this->activeBotUserId)
            ->where('status', AiMessage::STATUS_PENDING)
            ->first();

        if ($draft === null) {
            return;
        }

        $this->replyText = (string) $draft->text_ai;

        app(AiCancelMessage::class)->executeForDraft($draft);

        $this->loadPendingAiDrafts();
    }

    /**
     * Render the component view.
     *
     * @return \Illuminate\View\View
     */
    public function render(): \Illuminate\View\View
    {
        return view('livewire.chat.conversation-page');
    }
}

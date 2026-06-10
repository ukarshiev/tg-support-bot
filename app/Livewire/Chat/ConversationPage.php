<?php

declare(strict_types=1);

namespace App\Livewire\Chat;

use App\Models\AutoReply;
use App\Models\BotUser;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Modules\Admin\Actions\BanBotUser;
use App\Modules\Admin\Actions\ClearBotUserHistory;
use App\Modules\Admin\Actions\DeleteBotUser;
use App\Modules\Admin\Actions\SendReplyAction;
use App\Modules\Admin\Actions\UnbanBotUser;
use App\Modules\Telegram\Actions\CloseTopic;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Full-screen manager chat workspace (standalone Livewire route).
 *
 * Accessible at GET /admin/chats (admin auth only).
 * Uses a minimal full-screen layout (no Filament chrome).
 *
 * Data / logic is identical to the old Filament ConversationPage — the same
 * $dialogList, $activeBotUser, $chatMessages, search, statusFilter, 5 s polling,
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

    /** Number of messages loaded per page (initial load + each scroll-up batch). */
    private const MESSAGES_PER_PAGE = 50;

    /**
     * Whether older messages remain to be loaded — drives the scroll-up loader
     * and the "load more" indicator at the top of the thread.
     */
    public bool $hasMoreMessages = false;

    public string $replyText = '';

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
        $this->lastSeenMessageId = (int) Message::max('id');
        $this->loadDialogList();
    }

    /**
     * Livewire polling interval.
     *
     * @return string|null
     */
    public function getPollingInterval(): ?string
    {
        return '5s';
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

            return;
        }

        $this->activeBotUserId = $botUserId;
        $this->activeBotUser = BotUser::with(['externalUser'])->find($botUserId);

        // Opening a dialog marks ALL of its current messages read — the indicator
        // stays cleared across page reloads (persisted, not just session state).
        if ($this->activeBotUser) {
            $this->markConversationRead($this->activeBotUser);
        }

        $this->loadMessages();
        $this->loadDialogList();

        // Always scroll to the bottom when opening a dialog.
        $this->dispatch('messages-updated');
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
     * Combined 5 s poll entry point (wire:poll): refresh the dialog list and,
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

        if ($added > 0) {
            $this->markConversationRead($this->activeBotUser);
            $this->dispatch('messages-updated');
        }
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

        $chatId = (string) (BotUser::where('id', $latest->bot_user_id)->value('chat_id') ?? '');
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
            ->with(['externalMessage', 'attachments'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::MESSAGES_PER_PAGE + 1)
            ->get();

        $this->hasMoreMessages = $batch->count() > self::MESSAGES_PER_PAGE;

        $this->chatMessages = $batch->take(self::MESSAGES_PER_PAGE)
            ->reverse()
            ->values();
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
            ->with(['externalMessage', 'attachments'])
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
            ->with(['externalMessage', 'attachments'])
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

        SendReplyAction::execute($this->activeBotUser, $this->replyText, $file);

        $this->replyText = '';
        $this->attachment = null;
        $this->loadMessages();
        $this->loadDialogList();
        $this->dispatch('messages-updated');

        Notification::make()
            ->title('Сообщение отправлено')
            ->success()
            ->send();
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
     * Only VK exposes an addressable web profile from the data we store —
     * `https://vk.com/id{chat_id}` (numeric VK user id). Telegram is intentionally
     * excluded: a working profile link needs a public `@username` (which we do not
     * store) — a numeric id cannot be resolved (`tg://user?id=` does not open an
     * arbitrary user; Telegram requires a username or internal access_hash). All
     * other platforms / non-numeric ids return null, hiding the «Ссылка на профиль» row.
     *
     * @return string|null
     */
    public function profileUrl(): ?string
    {
        $user = $this->activeBotUser;

        if ($user === null) {
            return null;
        }

        $chatId = trim((string) $user->chat_id);

        if ($chatId === '' || ! ctype_digit($chatId)) {
            return null;
        }

        return match ($user->platform) {
            'vk' => "https://vk.com/id{$chatId}",
            default => null,
        };
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

        Notification::make()
            ->title('Диалог закрыт')
            ->success()
            ->send();
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
        if (empty($this->activeBotUser)) {
            return;
        }

        app(DeleteBotUser::class)->execute($this->activeBotUser);

        $this->activeBotUserId = null;
        $this->activeBotUser = null;
        $this->chatMessages = collect();
        $this->loadDialogList();

        Notification::make()
            ->title('Чат удалён')
            ->success()
            ->send();
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

        Notification::make()
            ->title('История очищена')
            ->success()
            ->send();
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

        Notification::make()
            ->title('Пользователь заблокирован')
            ->success()
            ->send();
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

        Notification::make()
            ->title('Пользователь разблокирован')
            ->success()
            ->send();
    }

    /**
     * Whether the reply form should be rendered in the chat workspace.
     *
     * Always true: SendReplyAction routes the reply by BotUser platform
     * (telegram/vk/external) and does not depend on MANAGER_INTERFACE, so the
     * manager can reply from the workspace regardless of the active mode.
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
        return AutoReply::where('enabled', true)->orderBy('id')->get();
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

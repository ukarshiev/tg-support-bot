<?php

declare(strict_types=1);

namespace App\Livewire\Chat;

use App\Models\BotUser;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Modules\Admin\Actions\BanBotUser;
use App\Modules\Admin\Actions\SendReplyAction;
use App\Modules\Admin\Actions\UnbanBotUser;
use App\Modules\Telegram\Actions\CloseTopic;
use Filament\Notifications\Notification;
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
 * sendReply, insertQuickReply, shouldShowReplyForm, getImageAttachments are kept.
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

    // ── Chat area ──────────────────────────────────────────────────────────────

    #[Locked]
    public Collection $chatMessages;

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
        $this->dialogList = BotUser::with(['lastMessage'])
            ->selectRaw(
                'bot_users.*, '
                . '(SELECT MAX(m.created_at) FROM messages m WHERE m.bot_user_id = bot_users.id) '
                . 'AS last_message_at'
            )
            ->when(
                $this->search !== '',
                fn ($q) => $q->where('chat_id', 'like', '%' . $this->search . '%')
            )
            ->when($this->statusFilter === 'open', fn ($q) => $q->where('is_closed', false))
            ->when($this->statusFilter === 'closed', fn ($q) => $q->where('is_closed', true))
            ->orderByRaw(
                'COALESCE((SELECT MAX(m.created_at) FROM messages m WHERE m.bot_user_id = bot_users.id), \'1970-01-01\') DESC'
            )
            ->get();
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
     * Triggered when the search field changes (wire:model.live.debounce).
     *
     * @return void
     */
    public function updatedSearch(): void
    {
        $this->loadDialogList();
    }

    /**
     * Triggered when the status-filter tab changes.
     *
     * @return void
     */
    public function updatedStatusFilter(): void
    {
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

        // Mark the conversation read so the unread indicator stays cleared
        // across page reloads (persisted, not just session state).
        $this->activeBotUser?->update(['manager_last_read_at' => now()]);

        $this->loadMessages();
        $this->loadDialogList();

        // Always scroll to the bottom when opening a dialog.
        $this->dispatch('messages-updated');
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

        if (! $this->activeBotUser) {
            return;
        }

        $previousCount = $this->chatMessages->count();
        $this->loadMessages();

        if ($this->chatMessages->count() > $previousCount) {
            $this->activeBotUser->update(['manager_last_read_at' => now()]);
            $this->dispatch('messages-updated');
        }
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

            return;
        }

        $this->chatMessages = Message::where('bot_user_id', $this->activeBotUserId)
            ->with(['externalMessage', 'attachments'])
            ->orderBy('created_at')
            ->get();
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

        $this->validate([
            'replyText' => [$file ? 'nullable' : 'required', 'string', 'max:4096'],
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
     * Only telegram and vk have a file-aware branch in SendReplyAction;
     * external/max replies are text-only, so the attach control is hidden there.
     *
     * @return bool
     */
    public function supportsAttachments(): bool
    {
        return in_array($this->activeBotUser?->platform, ['telegram', 'vk'], true);
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
     * Return all image attachments (photo/sticker) for the active dialog.
     *
     * @return \Illuminate\Support\Collection<int, MessageAttachment>
     */
    public function getImageAttachments(): Collection
    {
        if (! $this->activeBotUser) {
            return collect();
        }

        return MessageAttachment::whereIn(
            'message_id',
            Message::where('bot_user_id', $this->activeBotUserId)->pluck('id')
        )->whereIn('file_type', ['photo', 'sticker'])->get();
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

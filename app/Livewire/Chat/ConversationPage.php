<?php

declare(strict_types=1);

namespace App\Livewire\Chat;

use App\Models\BotUser;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Modules\Admin\Actions\SendReplyAction;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

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
        $this->loadMessages();
    }

    // ── Chat area ──────────────────────────────────────────────────────────────

    /**
     * Load messages for the active conversation.
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

        $this->dispatch('messages-updated');
    }

    /**
     * Send a manager reply to the active bot user.
     *
     * @return void
     */
    public function sendReply(): void
    {
        if (! $this->shouldShowReplyForm() || empty($this->activeBotUser)) {
            return;
        }

        $this->validate([
            'replyText' => ['required', 'string', 'max:4096'],
        ]);

        SendReplyAction::execute($this->activeBotUser, $this->replyText);

        $this->replyText = '';
        $this->loadMessages();
        $this->loadDialogList();

        Notification::make()
            ->title('Сообщение отправлено')
            ->success()
            ->send();
    }

    /**
     * Show reply form only in admin_panel mode.
     *
     * @return bool
     */
    public function shouldShowReplyForm(): bool
    {
        return config('app.manager_interface') === 'admin_panel';
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

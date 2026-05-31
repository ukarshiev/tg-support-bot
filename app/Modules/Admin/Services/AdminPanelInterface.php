<?php

namespace App\Modules\Admin\Services;

use App\Contracts\ManagerInterfaceContract;
use App\Models\BotUser;
use App\Models\Message;
use App\Modules\Telegram\DTOs\TelegramUpdateDto;

class AdminPanelInterface implements ManagerInterfaceContract
{
    /**
     * Save the incoming message to the database so Livewire polling
     * can display it in the admin panel.
     *
     * @param BotUser           $botUser User who sent the message
     * @param TelegramUpdateDto $dto     Message data
     *
     * @return void
     */
    public function notifyIncomingMessage(BotUser $botUser, TelegramUpdateDto $dto): void
    {
        $message = Message::create([
            'bot_user_id' => $botUser->id,
            'platform' => $botUser->platform,
            'message_type' => 'incoming',
            'from_id' => $dto->messageId ?? 0,
            'to_id' => 0,
            'text' => $dto->text ?? $dto->caption ?? null,
        ]);

        if (!empty($dto->fileId)) {
            $message->attachments()->create([
                'file_id' => $dto->fileId,
                'file_type' => $dto->fileType ?? 'document',
            ]);
        }
    }

    /**
     * In admin_panel mode a Telegram forum topic is not required.
     * Conversations are visible in the chat workspace (/admin/chats) using the BotUser model.
     *
     * @param int $botUserId New user ID
     *
     * @return void
     */
    public function createConversation(int $botUserId): void
    {
        // No-op: the conversation appears automatically in the chat workspace (/admin/chats).
    }
}

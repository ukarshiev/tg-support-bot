<?php

namespace App\Modules\Telegram\Actions;

use App\Modules\Telegram\Api\TelegramMethods;
use App\Modules\Telegram\DTOs\TelegramUpdateDto;

/**
 * Action: Send language selection on Telegram start.
 */
class SendStartMessage
{
    public function __construct(
        private TelegramMethods $telegramMethods,
        private SendLanguageSelectionMessage $sendLanguageSelectionMessage,
    ) {
    }

    /**
     * Send start language selector.
     *
     * @param TelegramUpdateDto $update
     *
     * @return void
     */
    public function execute(TelegramUpdateDto $update): void
    {
        $this->telegramMethods->sendQueryTelegram('deleteMessage', [
            'chat_id' => $update->chatId,
            'message_id' => $update->messageId,
        ]);

        $this->sendLanguageSelectionMessage->execute($update);
    }
}

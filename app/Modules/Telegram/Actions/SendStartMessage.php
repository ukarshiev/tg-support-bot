<?php

namespace App\Modules\Telegram\Actions;

use App\Modules\Telegram\DTOs\TelegramUpdateDto;

/**
 * Action: Send language selection on Telegram start.
 */
class SendStartMessage
{
    public function __construct(
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
        $this->sendLanguageSelectionMessage->execute($update, force: true);
    }

    public function force(TelegramUpdateDto $update): void
    {
        $this->sendLanguageSelectionMessage->execute($update, force: true);
    }
}

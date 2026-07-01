<?php

namespace App\Modules\Telegram\Actions;

use App\Models\BotUser;
use App\Modules\Telegram\DTOs\TelegramUpdateDto;
use App\Modules\Telegram\DTOs\TGTextMessageDto;
use App\Modules\Telegram\Jobs\SendTelegramMessageJob;
use App\Modules\Telegram\Services\SupportLanguageService;

class SendLanguageSelectionMessage
{
    public function __construct(
        private readonly SupportLanguageService $languages,
    ) {
    }

    public function execute(TelegramUpdateDto $update): void
    {
        if ($update->typeSource !== 'private') {
            return;
        }

        $botUser = BotUser::getOrCreateByTelegramUpdate($update);
        if ($botUser === null) {
            return;
        }

        SendTelegramMessageJob::dispatch(
            $botUser->id,
            $update,
            TGTextMessageDto::from([
                'methodQuery' => 'sendMessage',
                'chat_id' => $update->chatId,
                'message_thread_id' => $update->messageThreadId,
                'text' => $this->languages->prompt(),
                'parse_mode' => 'html',
                'reply_markup' => [
                    'inline_keyboard' => $this->languages->keyboard(),
                ],
            ]),
            'outgoing'
        );
    }
}

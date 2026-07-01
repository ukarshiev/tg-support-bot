<?php

namespace App\Modules\Telegram\Actions;

use App\Models\BotUser;
use App\Modules\Telegram\DTOs\TGTextMessageDto;
use App\Modules\Telegram\Jobs\SendTelegramSimpleQueryJob;

class SendTypingAction
{
    public function execute(BotUser $botUser): void
    {
        if ($botUser->platform !== 'telegram') {
            return;
        }

        SendTelegramSimpleQueryJob::dispatch(TGTextMessageDto::from([
            'methodQuery' => 'sendChatAction',
            'chat_id' => $botUser->chat_id,
            'parse_mode' => null,
            'typeSource' => null,
            'text' => null,
            'action' => 'typing',
        ]));
    }
}

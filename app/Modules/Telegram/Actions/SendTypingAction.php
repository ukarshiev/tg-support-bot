<?php

namespace App\Modules\Telegram\Actions;

use App\Models\BotUser;
use App\Modules\Telegram\Api\TelegramMethods;
use App\Modules\Telegram\DTOs\TGTextMessageDto;
use App\Modules\Telegram\Jobs\SendTelegramSimpleQueryJob;
use Illuminate\Support\Facades\Log;

class SendTypingAction
{
    /**
     * Мгновенно показать клиенту Telegram статус «печатает».
     *
     * Важно: AI-ответ генерируется внутри queue job. Если отправлять typing
     * отдельной job в ту же очередь, она может выполниться только после ответа AI.
     * Поэтому для AI-сценария нужен прямой вызов Telegram API.
     */
    public function execute(BotUser $botUser): void
    {
        if ($botUser->platform !== 'telegram') {
            return;
        }

        $response = TelegramMethods::sendQueryTelegram('sendChatAction', [
            'chat_id' => $botUser->chat_id,
            'action' => 'typing',
        ]);

        if ($response->ok !== true) {
            Log::channel('app')->warning('SendTypingAction: Telegram typing action failed', [
                'source' => 'telegram_typing_action_failed',
                'bot_user_id' => $botUser->id,
                'chat_id' => $botUser->chat_id,
                'response' => $response->rawData,
            ]);
        }
    }

    public function dispatch(BotUser $botUser): void
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

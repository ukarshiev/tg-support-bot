<?php

namespace App\Modules\Ai\Actions;

use App\Modules\Telegram\DTOs\TelegramUpdateDto;
use App\Modules\Telegram\DTOs\TGTextMessageDto;
use App\Modules\Telegram\Jobs\SendTelegramSimpleQueryJob;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Log;

class AiEditHintMessage extends AiAction
{
    public function execute(TelegramUpdateDto $update): void
    {
        try {
            SendTelegramSimpleQueryJob::dispatch(TGTextMessageDto::from([
                'token' => (string) app(SettingsService::class)->get('telegram_ai.token'),
                'methodQuery' => 'answerCallbackQuery',
                'chat_id' => 0,
                'callback_query_id' => $update->callbackId,
                'text' => 'Ответьте reply на AI-подсказку своим текстом — он уйдёт клиенту.',
                'parse_mode' => null,
            ]));
        } catch (\Throwable $e) {
            Log::channel('app')->error($e->getMessage(), ['source' => 'ai_error']);
        }
    }
}

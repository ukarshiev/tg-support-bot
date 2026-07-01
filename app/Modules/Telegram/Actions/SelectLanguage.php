<?php

namespace App\Modules\Telegram\Actions;

use App\Models\BotUser;
use App\Modules\Telegram\DTOs\TelegramUpdateDto;
use App\Modules\Telegram\DTOs\TGTextMessageDto;
use App\Modules\Telegram\Jobs\SendTelegramMessageJob;
use App\Modules\Telegram\Services\SupportLanguageService;

class SelectLanguage
{
    public function __construct(
        private readonly SupportLanguageService $languages,
    ) {
    }

    public function execute(BotUser $botUser, TelegramUpdateDto $update): void
    {
        $code = $this->languages->codeFromCallback($update->callbackData);
        $language = $this->languages->find($code);

        if ($code === null || $language === null) {
            return;
        }

        $botUser->update([
            'preferred_language_code' => $code,
            'preferred_language_name' => $language['name'],
            'preferred_language_selected_at' => now(),
        ]);
        SendTelegramMessageJob::dispatch(
            $botUser->id,
            $update,
            TGTextMessageDto::from([
                'methodQuery' => 'sendMessage',
                'chat_id' => $botUser->chat_id,
                'text' => $this->languages->greeting($code),
                'parse_mode' => 'html',
            ]),
            'outgoing'
        );
    }
}


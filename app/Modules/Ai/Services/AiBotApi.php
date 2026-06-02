<?php

namespace App\Modules\Ai\Services;

use App\Modules\Telegram\Api\TelegramMethods;
use App\Modules\Telegram\DTOs\TelegramAnswerDto;
use App\Services\Settings\SettingsService;

class AiBotApi
{
    /**
     * Send a Telegram API request using the AI bot token.
     *
     * Delegates to TelegramMethods::sendQueryTelegram but always injects
     * the AI bot token (TELEGRAM_AI_BOT_TOKEN) instead of the main bot token.
     *
     * @param string     $methodQuery Telegram API method name
     * @param array|null $dataQuery   Request payload
     *
     * @return TelegramAnswerDto
     */
    public function send(string $methodQuery, ?array $dataQuery = null): TelegramAnswerDto
    {
        $token = (string) app(SettingsService::class)->get('telegram_ai.token');

        return TelegramMethods::sendQueryTelegram($methodQuery, $dataQuery, $token);
    }
}

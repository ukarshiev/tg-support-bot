<?php

namespace App\Modules\Telegram\Api;

use App\Modules\Telegram\DTOs\TelegramAnswerDto;
use App\Services\Settings\SettingsService;

class TelegramMethods
{
    /**
     * Send request to Telegram with rate limit check.
     *
     * @param string $methodQuery
     * @param ?array $dataQuery
     *
     * @return TelegramAnswerDto
     */
    public static function sendQueryTelegram(string $methodQuery, ?array $dataQuery = null, ?string $token = null): TelegramAnswerDto
    {
        try {
            $token = $token ?? (string) app(SettingsService::class)->get('telegram.token');

            $domainQuery = 'https://api.telegram.org/bot' . $token . '/';
            $urlQuery = $domainQuery . $methodQuery;

            if (!empty($dataQuery['uploaded_file']) || !empty($dataQuery['uploaded_file_path'])) {
                $attachType = match ($methodQuery) {
                    'sendPhoto' => 'photo',
                    'sendVoice' => 'voice',
                    'sendAudio' => 'audio',
                    'sendVideo' => 'video',
                    default => 'document',
                };
                $resultQuery = ParserMethods::attachQuery($urlQuery, $dataQuery, $attachType);
            } else {
                $resultQuery = ParserMethods::postQuery($urlQuery, $dataQuery);
            }

            return TelegramAnswerDto::fromData($resultQuery);
        } catch (\Throwable $e) {
            return TelegramAnswerDto::fromData([
                'ok' => false,
                'response_code' => 500,
                'result' => $e->getMessage(),
            ]);
        }
    }
}

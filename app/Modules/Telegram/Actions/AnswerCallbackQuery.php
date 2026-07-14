<?php

namespace App\Modules\Telegram\Actions;

use App\Modules\Telegram\DTOs\TelegramUpdateDto;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AnswerCallbackQuery
{
    private const TIMEOUT_SECONDS = 2;

    public function execute(TelegramUpdateDto $update, ?string $text = null): void
    {
        if (empty($update->callbackId)) {
            return;
        }

        $token = (string) app(SettingsService::class)->get('telegram.token');
        if ($token === '') {
            Log::channel('app')->warning('AnswerCallbackQuery: telegram.token is empty', [
                'source' => 'telegram_answer_callback_empty_token',
            ]);

            return;
        }

        try {
            $payload = [
                'callback_query_id' => $update->callbackId,
                'cache_time' => 0,
            ];

            if ($text !== null && $text !== '') {
                $payload['text'] = mb_substr($text, 0, 200);
            }

            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::TIMEOUT_SECONDS)
                ->post("https://api.telegram.org/bot{$token}/answerCallbackQuery", $payload);

            if (! $response->json('ok')) {
                Log::channel('app')->warning('AnswerCallbackQuery: Telegram API returned non-ok response', [
                    'source' => 'telegram_answer_callback_non_ok',
                    'status' => $response->status(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::channel('app')->warning('AnswerCallbackQuery: failed to answer callback quickly', [
                'source' => 'telegram_answer_callback_failed',
                'error_type' => $e::class,
            ]);
        }
    }
}

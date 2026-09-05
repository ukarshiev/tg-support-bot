<?php

namespace App\Modules\Telegram\Api;

use App\Modules\Telegram\DTOs\TelegramAnswerDto;
use App\Modules\Telegram\Support\TelegramOutgoingMessageLimiter;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TelegramMethods
{
    /**
     * Send request to Telegram with rate limit check.
     *
     * @param string      $methodQuery    Telegram API method
     * @param array|null  $dataQuery      Telegram request payload
     * @param string|null $token          Bot token override
     * @param string|null $idempotencyKey Stable operation key for multipart retries
     *
     * @return TelegramAnswerDto
     */
    public static function sendQueryTelegram(
        string $methodQuery,
        ?array $dataQuery = null,
        ?string $token = null,
        ?string $idempotencyKey = null,
    ): TelegramAnswerDto {
        try {
            $token = $token ?? (string) app(SettingsService::class)->get('telegram.token');

            $domainQuery = 'https://api.telegram.org/bot' . $token . '/';

            $requests = app(TelegramOutgoingMessageLimiter::class)->prepare($methodQuery, $dataQuery ?? []);
            if (count($requests) === 1) {
                $response = self::sendSingleRequest($domainQuery, $requests[0]['method'], $requests[0]['data']);
                if (!$response->ok) {
                    self::logOversizedRejection($response, $requests[0]['method'], $requests[0]['data']);
                }

                return $response;
            }

            $sequenceKey = $idempotencyKey ?? self::fallbackSequenceKey($token, $methodQuery, $dataQuery ?? []);
            $cacheKey = 'telegram:request-sequence:' . hash('sha256', $sequenceKey);
            $lockKey = $cacheKey . ':lock';
            $retainCheckpoint = $idempotencyKey !== null;

            return Cache::lock($lockKey, 60)->block(10, function () use ($cacheKey, $domainQuery, $requests, $retainCheckpoint): TelegramAnswerDto {
                /** @var array{responses?: array<int, array<string, mixed>>} $checkpoint */
                $checkpoint = Cache::get($cacheKey, []);
                $responses = $checkpoint['responses'] ?? [];
                $primaryResponse = null;

                foreach ($requests as $index => $request) {
                    if (isset($responses[$index])) {
                        $response = self::answerFromData($responses[$index], $request['method']);
                    } else {
                        $response = self::sendSingleRequest($domainQuery, $request['method'], $request['data']);
                        if ($response->ok) {
                            // Telegram не поддерживает idempotency keys. Поэтому после
                            // каждой подтверждённой части сохраняем ответ в общем Cache: retry
                            // пропускает уже доставленные части и продолжает с места сбоя.
                            $responses[$index] = self::checkpointResponse($response);
                            Cache::put($cacheKey, ['responses' => $responses], now()->addDay());
                        }
                    }

                    if (!$response->ok) {
                        self::logOversizedRejection($response, $request['method'], $request['data']);

                        return $response;
                    }

                    if ($request['primary']) {
                        $primaryResponse = $response;
                    }
                }

                $primaryResponse ??= throw new \RuntimeException('Telegram request sequence has no primary response');
                if (!$retainCheckpoint) {
                    Cache::forget($cacheKey);
                }

                return $primaryResponse;
            });
        } catch (\Throwable $e) {
            return self::answerFromData([
                'ok' => false,
                'response_code' => 500,
                'result' => $e->getMessage(),
            ]);
        }
    }

    /** @param array<string, mixed> $dataQuery */
    private static function fallbackSequenceKey(string $token, string $method, array $dataQuery): string
    {
        $payload = json_encode($dataQuery, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return implode('|', [hash('sha256', $token), $method, hash('sha256', $payload !== false ? $payload : print_r($dataQuery, true))]);
    }

    /** @return array<string, mixed> */
    private static function checkpointResponse(TelegramAnswerDto $response): array
    {
        return [
            'ok' => true,
            'result' => array_filter([
                'message_id' => $response->message_id,
                'chat' => $response->chat_id === null ? null : ['id' => $response->chat_id],
                'message_thread_id' => $response->message_thread_id,
                'date' => $response->date,
            ], static fn (mixed $value): bool => $value !== null),
        ];
    }

    /** @param array<string, mixed> $dataQuery */
    private static function sendSingleRequest(string $domainQuery, string $methodQuery, array $dataQuery): TelegramAnswerDto
    {
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

        return self::answerFromData($resultQuery, $methodQuery);
    }

    /** @param array<string, mixed> $dataQuery */
    private static function logOversizedRejection(TelegramAnswerDto $response, string $method, array $dataQuery): void
    {
        if ($response->type_error !== 'MESSAGE_TOO_LONG') {
            return;
        }

        $content = $dataQuery['text'] ?? $dataQuery['caption'] ?? '';
        Log::channel('app')->error('Telegram rejected an oversized outgoing message; retry disabled', [
            'source' => 'telegram_message_too_long',
            'method' => $method,
            'actual_length' => is_string($content) ? mb_strlen($content) : null,
        ]);
    }

    /** @param array<string, mixed> $data */
    private static function answerFromData(array $data, ?string $method = null): TelegramAnswerDto
    {
        return TelegramAnswerDto::fromData($data, $method)
            ?? new TelegramAnswerDto(ok: false, response_code: 500);
    }
}

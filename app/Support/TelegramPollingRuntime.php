<?php

namespace App\Support;

use App\Enums\TelegramPollerApiResult;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class TelegramPollingRuntime
{
    private const HEARTBEAT_PREFIX = 'telegram:poller:heartbeat:';

    private const LOG_THROTTLE_PREFIX = 'telegram:poller:log-throttle:';

    public function resetHeartbeat(string $channel): void
    {
        Cache::forget($this->heartbeatKey($channel));
    }

    public function beat(string $channel): void
    {
        Cache::forever($this->heartbeatKey($channel), now()->getTimestamp());
    }

    public function isHealthy(string $channel, int $maxAgeSeconds): bool
    {
        $heartbeat = Cache::get($this->heartbeatKey($channel));

        return is_numeric($heartbeat)
            && now()->getTimestamp() - (int) $heartbeat <= max(1, $maxAgeSeconds);
    }

    public function preflight(string $channel, string $label, string $token): TelegramPollerApiResult
    {
        try {
            $response = Http::connectTimeout(2)
                ->timeout(10)
                ->when(config('traffic_source.telegram.force_ipv4'), fn ($client) => $client->withOptions(['force_ip_resolve' => 'v4']))
                ->get("https://api.telegram.org/bot{$token}/getMe");
        } catch (\Throwable $e) {
            $this->logThrottled($channel, 'get_me_transport', 'warning', "{$label}: getMe transport failed; retrying", [
                'source' => $this->source($channel, 'get_me_transport_failed'),
                'error' => $this->sanitize($e->getMessage()),
            ]);

            return TelegramPollerApiResult::TransientFailure;
        }

        return $this->classifyResponse($channel, $label, 'get_me', $response);
    }

    public function reportTransportFailure(
        string $channel,
        string $label,
        string $operation,
        \Throwable $error,
        array $context = [],
    ): TelegramPollerApiResult {
        $this->logThrottled(
            $channel,
            "{$operation}:transport",
            'warning',
            "{$label}: {$operation} transport failed; retrying",
            [
                ...$context,
                'source' => $this->source($channel, "{$operation}_transport_failed"),
                'error' => $this->sanitize($error->getMessage()),
            ],
        );

        return TelegramPollerApiResult::TransientFailure;
    }

    public function classifyResponse(
        string $channel,
        string $label,
        string $operation,
        Response $response,
    ): TelegramPollerApiResult {
        if ($response->json('ok')) {
            return TelegramPollerApiResult::Success;
        }

        $permanent = in_array($response->status(), [401, 404], true);
        $result = $permanent
            ? TelegramPollerApiResult::PermanentFailure
            : TelegramPollerApiResult::TransientFailure;

        $this->logThrottled(
            $channel,
            "{$operation}:{$response->status()}",
            $permanent ? 'error' : 'warning',
            $permanent
                ? "{$label}: Telegram rejected the bot token; waiting for replacement"
                : "{$label}: Telegram {$operation} returned non-ok; retrying",
            [
                'source' => $this->source($channel, "{$operation}_failed"),
                'status' => $response->status(),
                'body' => $this->sanitize($response->body()),
            ],
        );

        return $result;
    }

    public function retryDelay(TelegramPollerApiResult $result, int $transientDelay): int
    {
        return $result === TelegramPollerApiResult::PermanentFailure
            ? 60
            : max(1, $transientDelay);
    }

    public function sanitize(string $message): string
    {
        return preg_replace('/bot[0-9]+:[A-Za-z0-9_-]+/', 'bot[hidden]', $message) ?? $message;
    }

    private function logThrottled(
        string $channel,
        string $failure,
        string $level,
        string $message,
        array $context,
    ): void {
        if (! Cache::add(self::LOG_THROTTLE_PREFIX . "{$channel}:{$failure}", true, now()->addMinutes(5))) {
            return;
        }

        if ($level === 'error') {
            Log::channel('app')->error($message, $context);

            return;
        }

        Log::channel('app')->warning($message, $context);
    }

    private function heartbeatKey(string $channel): string
    {
        return self::HEARTBEAT_PREFIX . $channel;
    }

    private function source(string $channel, string $event): string
    {
        return ($channel === 'ai' ? 'ai_bot_poller_' : 'telegram_poller_') . $event;
    }
}

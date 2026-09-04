<?php

namespace App\Modules\Telegram\Support;

final class TelegramClientDeliveryRetryPolicy
{
    public const ATTEMPTS = 9;

    /**
     * Eight growing pauses give Telegram about 15.5 minutes to recover.
     * The sequence comfortably covers the measured five-minute outage while
     * avoiding rapid repeated requests to an already degraded endpoint.
     *
     * @var list<int>
     */
    public const BACKOFF_SECONDS = [5, 10, 20, 40, 80, 160, 240, 300];

    public const REQUEST_TIMEOUT_SECONDS = 8;

    public const QUEUE_ALLOWANCE_SECONDS = 15;

    public const CANARY_CONFIRMATION_TIMEOUT_SECONDS = 150;

    public const CANARY_RUN_DEADLINE_LIMIT_SECONDS = 3600;

    public static function retryDelayAfterAttempt(int $attempt): int
    {
        if ($attempt < 1 || $attempt >= self::ATTEMPTS) {
            throw new \InvalidArgumentException('Retry delay is only defined between delivery attempts.');
        }

        return self::BACKOFF_SECONDS[$attempt - 1];
    }

    public static function retryWindowSeconds(): int
    {
        return (self::ATTEMPTS * self::REQUEST_TIMEOUT_SECONDS)
            + array_sum(self::BACKOFF_SECONDS);
    }

    public static function canaryConfirmationTimeoutSeconds(): int
    {
        return self::CANARY_CONFIRMATION_TIMEOUT_SECONDS;
    }
}

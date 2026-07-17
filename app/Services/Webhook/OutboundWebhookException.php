<?php

declare(strict_types=1);

namespace App\Services\Webhook;

use RuntimeException;

class OutboundWebhookException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Webhook;

readonly class ValidatedWebhookUrl
{
    /**
     * @param list<string> $addresses
     */
    public function __construct(
        public string $url,
        public string $host,
        public array $addresses,
    ) {
    }

    /**
     * @return list<string>
     */
    public function curlResolve(): array
    {
        if (filter_var($this->host, FILTER_VALIDATE_IP)) {
            return [];
        }

        return [$this->host . ':443:' . implode(',', $this->addresses)];
    }
}

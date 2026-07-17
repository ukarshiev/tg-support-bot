<?php

declare(strict_types=1);

namespace App\Services\Webhook;

class OutboundWebhookUrlPolicy
{
    public function __construct(private readonly DnsResolver $dns)
    {
    }

    public function validate(string $url): ValidatedWebhookUrl
    {
        $parts = parse_url($url);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || (int) ($parts['port'] ?? 443) !== 443) {
            throw new OutboundWebhookException('invalid_url');
        }

        $host = trim(strtolower(rtrim((string) $parts['host'], '.')), '[]');
        if ($host === ''
            || $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || in_array($host, ['metadata.google.internal', 'metadata.google.com'], true)) {
            throw new OutboundWebhookException('private_address');
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $addresses = [$host];
        } else {
            $addresses = $this->dns->resolve($host);
            if ($addresses === []) {
                throw new OutboundWebhookException('dns_failed');
            }
        }

        foreach ($addresses as $address) {
            if (! $this->isPublicAddress($address)) {
                throw new OutboundWebhookException('private_address');
            }
        }

        return new ValidatedWebhookUrl($url, $host, $addresses);
    }

    private function isPublicAddress(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}

<?php

namespace Tests\Unit\Services\Webhook;

use App\Services\Webhook\DnsResolver;
use App\Services\Webhook\OutboundWebhookException;
use App\Services\Webhook\OutboundWebhookUrlPolicy;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OutboundWebhookUrlPolicyTest extends TestCase
{
    #[DataProvider('invalidUrls')]
    public function test_rejects_invalid_or_dangerous_urls(string $url, string $reason): void
    {
        $dns = Mockery::mock(DnsResolver::class);
        $policy = new OutboundWebhookUrlPolicy($dns);

        try {
            $policy->validate($url);
            $this->fail('URL should have been rejected.');
        } catch (OutboundWebhookException $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    /** @return array<string, array{string, string}> */
    public static function invalidUrls(): array
    {
        return [
            'http' => ['http://example.com/hook', 'invalid_url'],
            'custom port' => ['https://example.com:8443/hook', 'invalid_url'],
            'credentials' => ['https://user:pass@example.com/hook', 'invalid_url'],
            'localhost' => ['https://localhost/hook', 'private_address'],
            'private IPv4' => ['https://10.0.0.1/hook', 'private_address'],
            'metadata IPv4' => ['https://169.254.169.254/latest/meta-data', 'private_address'],
            'private IPv6' => ['https://[fd00::1]/hook', 'private_address'],
            'metadata hostname' => ['https://metadata.google.internal/hook', 'private_address'],
        ];
    }

    public function test_rejects_dns_failure_and_mixed_public_private_answers(): void
    {
        $dns = Mockery::mock(DnsResolver::class);
        $dns->shouldReceive('resolve')->with('missing.example')->andReturn([]);
        $dns->shouldReceive('resolve')->with('mixed.example')->andReturn(['93.184.216.34', '127.0.0.1']);
        $policy = new OutboundWebhookUrlPolicy($dns);

        foreach ([
            'https://missing.example/hook' => 'dns_failed',
            'https://mixed.example/hook' => 'private_address',
        ] as $url => $reason) {
            try {
                $policy->validate($url);
                $this->fail('URL should have been rejected.');
            } catch (OutboundWebhookException $e) {
                $this->assertSame($reason, $e->reason);
            }
        }
    }

    public function test_accepts_all_public_answers_and_pins_every_address(): void
    {
        $dns = Mockery::mock(DnsResolver::class);
        $dns->shouldReceive('resolve')->with('hooks.example.com')->once()
            ->andReturn(['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946']);

        $validated = (new OutboundWebhookUrlPolicy($dns))->validate('https://hooks.example.com/events');

        $this->assertSame(['hooks.example.com:443:93.184.216.34,2606:2800:220:1:248:1893:25c8:1946'], $validated->curlResolve());
    }
}

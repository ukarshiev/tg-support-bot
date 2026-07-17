<?php

namespace Tests\Unit\Services\Webhook;

use App\Models\ExternalSource;
use App\Services\Webhook\OutboundWebhookUrlPolicy;
use App\Services\Webhook\ValidatedWebhookUrl;
use App\Services\Webhook\WebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class WebhookServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sends_exact_json_with_hmac_headers_and_without_redirects(): void
    {
        $source = ExternalSource::factory()->create([
            'webhook_url' => 'https://hooks.example.com/events',
            'webhook_key_id' => 'key_current',
            'webhook_signing_secret' => 'top-secret',
        ]);
        $payload = ['event' => 'message', 'text' => 'Привет'];
        $deliveryId = '018f8407-5b63-7f50-ae6d-7834ad1f5d27';
        $timestamp = 1_700_000_000;
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $policy = Mockery::mock(OutboundWebhookUrlPolicy::class);
        $policy->shouldReceive('validate')->once()->andReturn(new ValidatedWebhookUrl(
            $source->webhook_url,
            'hooks.example.com',
            ['93.184.216.34'],
        ));
        Http::fake([$source->webhook_url => Http::response('ok')]);

        $result = (new WebhookService($policy))->sendMessage($source, $payload, $deliveryId, $timestamp);

        $this->assertSame('ok', $result);
        Http::assertSent(function ($request) use ($body, $deliveryId, $timestamp): bool {
            $expected = 'v1=' . hash_hmac('sha256', $timestamp . '.' . $deliveryId . '.' . $body, 'top-secret');

            return $request->body() === $body
                && $request->header('X-Tg-Support-Webhook-Id')[0] === $deliveryId
                && $request->header('X-Tg-Support-Timestamp')[0] === (string) $timestamp
                && $request->header('X-Tg-Support-Key-Id')[0] === 'key_current'
                && $request->header('X-Tg-Support-Signature')[0] === $expected;
        });
    }
}

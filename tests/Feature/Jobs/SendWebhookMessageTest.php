<?php

namespace Tests\Feature\Jobs;

use App\Modules\External\Jobs\SendWebhookMessage;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SendWebhookMessageTest extends TestCase
{
    public function test_send_message_for_user(): void
    {
        $webhookUrl = 'https://example.com/webhook';
        $payload = ['event' => 'message', 'text' => 'Hello'];

        Http::fake([
            $webhookUrl => Http::response(['ok' => true], 200),
        ]);

        $job = new SendWebhookMessage($webhookUrl, $payload);
        $job->handle();

        Http::assertSent(function ($request) use ($webhookUrl, $payload) {
            return $request->url() === $webhookUrl
                && $request->data() === $payload;
        });
    }

    public function test_failed_webhook_throws_so_a_chain_cannot_confirm_delivery(): void
    {
        $webhookUrl = 'https://example.com/webhook';

        Http::fake([
            $webhookUrl => Http::response(['error' => 'failed'], 500),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Webhook delivery failed');

        (new SendWebhookMessage($webhookUrl, ['event' => 'message']))->handle();
    }
}

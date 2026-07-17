<?php

namespace Tests\Feature\Jobs;

use App\Models\ExternalSource;
use App\Modules\External\Jobs\SendWebhookMessage;
use App\Services\Webhook\WebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SendWebhookMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_keeps_delivery_id_stable_and_uses_current_source_configuration(): void
    {
        $source = ExternalSource::factory()->create(['webhook_url' => 'https://example.com/webhook']);
        $payload = ['event' => 'message', 'text' => 'Hello'];
        $job = new SendWebhookMessage($source->webhook_url, $payload, $source->id);
        $deliveryId = $job->deliveryId;

        $webhook = Mockery::mock(WebhookService::class);
        $webhook->shouldReceive('sendMessage')
            ->twice()
            ->withArgs(fn (ExternalSource $actual, array $data, string $id): bool => $actual->is($source)
                && $data === $payload
                && $id === $deliveryId)
            ->andReturn('ok');

        $job->handle($webhook);
        $job->handle($webhook);

        $this->assertSame($deliveryId, $job->deliveryId);
    }

    public function test_failed_webhook_throws_so_a_chain_cannot_confirm_delivery(): void
    {
        $source = ExternalSource::factory()->create(['webhook_url' => 'https://example.com/webhook']);
        $webhook = Mockery::mock(WebhookService::class);
        $webhook->shouldReceive('sendMessage')->once()->andReturnNull();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Webhook delivery failed');

        (new SendWebhookMessage($source->webhook_url, ['event' => 'message'], $source->id))->handle($webhook);
    }

    public function test_changed_url_invalidates_queued_delivery(): void
    {
        $source = ExternalSource::factory()->create(['webhook_url' => 'https://example.com/old']);
        $job = new SendWebhookMessage($source->webhook_url, ['event' => 'message'], $source->id);
        $source->update(['webhook_url' => 'https://example.com/new']);

        $webhook = Mockery::mock(WebhookService::class);
        $webhook->shouldNotReceive('sendMessage');

        $this->expectException(\RuntimeException::class);
        $job->handle($webhook);
    }
}

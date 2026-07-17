<?php

namespace Tests\Unit\Services\Webhook;

use App\Models\ExternalSource;
use App\Services\Webhook\WebhookSigningSecretService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WebhookSigningSecretServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_secret_is_encrypted_and_can_be_activated_atomically(): void
    {
        $source = ExternalSource::factory()->create();
        $service = new WebhookSigningSecretService();

        $created = $service->createPending($source);
        $rawStored = DB::table('external_sources')->where('id', $source->id)->value('pending_webhook_signing_secret');

        $this->assertStringStartsWith('whk_', $created['key_id']);
        $this->assertSame(64, strlen($created['secret']));
        $this->assertNotSame($created['secret'], $rawStored);
        $this->assertSame($created['secret'], $source->fresh()?->pending_webhook_signing_secret);

        $service->activatePending($source->fresh());
        $source->refresh();

        $this->assertSame($created['key_id'], $source->webhook_key_id);
        $this->assertSame($created['secret'], $source->webhook_signing_secret);
        $this->assertNull($source->pending_webhook_key_id);
        $this->assertNull($source->pending_webhook_signing_secret);
    }
}

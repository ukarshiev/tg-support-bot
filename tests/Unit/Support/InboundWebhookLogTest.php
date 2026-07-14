<?php

namespace Tests\Unit\Support;

use App\Support\InboundWebhookLog;
use Tests\TestCase;

class InboundWebhookLogTest extends TestCase
{
    public function test_telegram_summary_never_contains_message_or_secret(): void
    {
        $context = InboundWebhookLog::summarize('telegram', [
            'update_id' => 42,
            'secret' => 'do-not-log',
            'message' => [
                'message_id' => 7,
                'chat' => ['id' => 123],
                'text' => 'private support question',
                'photo' => [['file_id' => 'private-file-id']],
            ],
        ]);

        $encoded = json_encode($context, JSON_THROW_ON_ERROR);

        $this->assertSame('telegram_request', $context['source']);
        $this->assertSame(42, $context['event_id']);
        $this->assertTrue($context['has_text']);
        $this->assertStringNotContainsString('private support question', $encoded);
        $this->assertStringNotContainsString('do-not-log', $encoded);
        $this->assertStringNotContainsString('private-file-id', $encoded);
        $this->assertArrayNotHasKey('chat_id', $context);
    }

    public function test_vk_and_max_summaries_only_expose_operational_metadata(): void
    {
        $vk = InboundWebhookLog::summarize('vk', [
            'type' => 'message_new',
            'event_id' => 'evt-1',
            'secret' => 'vk-secret',
            'object' => ['message' => ['peer_id' => 55, 'text' => 'vk private']],
        ]);
        $max = InboundWebhookLog::summarize('max', [
            'update_type' => 'message_created',
            'update_id' => 9,
            'message' => [
                'sender' => ['user_id' => 88],
                'body' => [
                    'text' => 'max private',
                    'attachments' => [['type' => 'image', 'payload' => 'secret attachment']],
                ],
            ],
        ]);

        $encoded = json_encode([$vk, $max], JSON_THROW_ON_ERROR);

        $this->assertSame('message_new', $vk['event_type']);
        $this->assertSame('message_created', $max['event_type']);
        $this->assertSame(1, $max['attachments_count']);
        $this->assertStringNotContainsString('private', $encoded);
        $this->assertStringNotContainsString('secret', $encoded);
        $this->assertArrayNotHasKey('peer_id', $vk);
        $this->assertArrayNotHasKey('user_id', $max);
    }
}

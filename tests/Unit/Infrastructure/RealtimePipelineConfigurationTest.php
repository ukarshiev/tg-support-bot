<?php

namespace Tests\Unit\Infrastructure;

use App\Events\ConversationMessageCommitted;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Tests\TestCase;

/** Regression coverage for the queued realtime delivery boundary. */
class RealtimePipelineConfigurationTest extends TestCase
{
    public function test_realtime_broadcast_is_queued_separately_from_delivery(): void
    {
        $event = new ConversationMessageCommitted(1, 2, 'outgoing', '2026-10-09T00:00:00Z', 'test-trace');
        $this->assertInstanceOf(ShouldBroadcast::class, $event);
        $this->assertNotInstanceOf(ShouldBroadcastNow::class, $event);
        $this->assertSame('broadcast', $event->broadcastQueue());
        $this->assertSame(3, $event->tries);
        $this->assertSame([1, 3], $event->backoff);
        $this->assertSame(3, config('broadcasting.connections.reverb.options.timeout'));
        $this->assertSame(['broadcast'], config('horizon.defaults.realtime.queue'));
        $this->assertSame(10, config('horizon.defaults.realtime.timeout'));
        $this->assertLessThan(config('queue.connections.redis.retry_after'), config('horizon.defaults.background.timeout'));
    }
}

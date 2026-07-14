<?php

namespace Tests\Feature\Modules\Max;

use App\Modules\Max\Controllers\MaxBotController;
use App\Modules\Max\Jobs\MirrorMaxIncomingMessageJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Mocks\Max\MaxUpdateDtoMock;
use Tests\TestCase;

class MaxBotControllerIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Queue::fake();
    }

    public function test_repeated_webhook_is_processed_once(): void
    {
        $payload = MaxUpdateDtoMock::getDtoParams();
        $request = Request::create('/api/max/bot', 'POST', $payload);

        $first = app(MaxBotController::class)->bot_query($request);
        $second = app(MaxBotController::class)->bot_query($request);

        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame(200, $second->getStatusCode());
        $this->assertDatabaseCount('messages', 1);
        Queue::assertPushed(MirrorMaxIncomingMessageJob::class, 1);
    }

    public function test_concurrent_duplicate_receives_retry_status_instead_of_false_success(): void
    {
        $payload = MaxUpdateDtoMock::getDtoParams();
        $eventId = (string) ($payload['event_id'] ?? $payload['timestamp']);
        $cacheKey = 'max_event_' . hash('sha256', $eventId);
        $lock = Cache::lock($cacheKey . ':lock', 30);
        $this->assertTrue($lock->get());

        try {
            $response = app(MaxBotController::class)->bot_query(
                Request::create('/api/max/bot', 'POST', $payload),
            );
            $this->assertSame(503, $response->getStatusCode());
            $this->assertDatabaseCount('messages', 0);
        } finally {
            $lock->release();
        }
    }
}

<?php

namespace Tests\Feature\Modules\Vk;

use App\Modules\Vk\Controllers\VkBotController;
use App\Modules\Vk\Jobs\MirrorVkIncomingMessageJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Mocks\Vk\VkUpdateDtoMock;
use Tests\TestCase;

class VkBotControllerIdempotencyTest extends TestCase
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
        $payload = VkUpdateDtoMock::getDtoParams();
        $request = Request::create('/api/vk/bot', 'POST', $payload);

        $first = app(VkBotController::class)->bot_query($request);
        $second = app(VkBotController::class)->bot_query($request);

        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame(200, $second->getStatusCode());
        $this->assertDatabaseCount('messages', 1);
        Queue::assertPushed(MirrorVkIncomingMessageJob::class, 1);
    }

    public function test_concurrent_duplicate_receives_retry_status_instead_of_false_success(): void
    {
        $payload = VkUpdateDtoMock::getDtoParams();
        $cacheKey = 'vk_event_' . hash('sha256', $payload['event_id']);
        $lock = Cache::lock($cacheKey . ':lock', 30);
        $this->assertTrue($lock->get());

        try {
            $response = app(VkBotController::class)->bot_query(
                Request::create('/api/vk/bot', 'POST', $payload),
            );
            $this->assertSame(503, $response->getStatusCode());
            $this->assertDatabaseCount('messages', 0);
        } finally {
            $lock->release();
        }
    }
}

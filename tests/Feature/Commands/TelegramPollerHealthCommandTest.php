<?php

namespace Tests\Feature\Commands;

use App\Support\TelegramPollingRuntime;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class TelegramPollerHealthCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_health_command_fails_without_heartbeat(): void
    {
        $this->artisan('telegram:poller-health', ['channel' => 'main'])->assertFailed();
    }

    public function test_health_command_succeeds_with_fresh_heartbeat(): void
    {
        app(TelegramPollingRuntime::class)->beat('ai');

        $this->artisan('telegram:poller-health', ['channel' => 'ai'])->assertSuccessful();
    }

    public function test_health_command_rejects_unknown_channel(): void
    {
        $this->artisan('telegram:poller-health', ['channel' => 'unknown'])->assertExitCode(2);
    }
}

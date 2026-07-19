<?php

namespace Tests\Unit\Support;

use App\Enums\TelegramPollerApiResult;
use App\Support\TelegramPollingRuntime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramPollingRuntimeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_preflight_accepts_valid_token(): void
    {
        Http::fake([
            'https://api.telegram.org/botvalid-token/getMe' => Http::response(['ok' => true, 'result' => ['id' => 1]]),
        ]);

        $result = app(TelegramPollingRuntime::class)->preflight('main', 'Telegram poller', 'valid-token');

        $this->assertSame(TelegramPollerApiResult::Success, $result);
    }

    public function test_preflight_treats_401_and_404_as_permanent_token_failures(): void
    {
        Http::fakeSequence()
            ->push(['ok' => false, 'description' => 'Unauthorized'], 401)
            ->push(['ok' => false, 'description' => 'Not Found'], 404);

        $runtime = app(TelegramPollingRuntime::class);

        $this->assertSame(
            TelegramPollerApiResult::PermanentFailure,
            $runtime->preflight('main', 'Telegram poller', 'revoked-token'),
        );
        $this->assertSame(
            TelegramPollerApiResult::PermanentFailure,
            $runtime->preflight('ai', 'AI bot poller', 'malformed-token'),
        );
        $this->assertSame(60, $runtime->retryDelay(TelegramPollerApiResult::PermanentFailure, 1));
    }

    public function test_preflight_treats_transport_error_as_transient_and_hides_token(): void
    {
        Http::fake(function (): never {
            throw new \RuntimeException('timeout for https://api.telegram.org/bot123:secret/getMe');
        });

        $runtime = app(TelegramPollingRuntime::class);

        $this->assertSame(
            TelegramPollerApiResult::TransientFailure,
            $runtime->preflight('main', 'Telegram poller', '123:secret'),
        );
        $this->assertSame('timeout for https://api.telegram.org/bot[hidden]/getMe', $runtime->sanitize(
            'timeout for https://api.telegram.org/bot123:secret/getMe',
        ));
    }

    public function test_heartbeat_becomes_stale_after_maximum_age(): void
    {
        Carbon::setTestNow('2026-07-19 10:00:00');
        $runtime = app(TelegramPollingRuntime::class);
        $runtime->beat('main');

        $this->assertTrue($runtime->isHealthy('main', 90));

        Carbon::setTestNow('2026-07-19 10:01:31');

        $this->assertFalse($runtime->isHealthy('main', 90));
        $runtime->resetHeartbeat('main');
        $this->assertFalse($runtime->isHealthy('main', 90));
    }
}

<?php

namespace Tests\Unit\Modules\External\Services\Source;

use App\Models\ExternalSource;
use App\Models\ExternalSourceAccessTokens;
use App\Modules\External\Services\Source\ExternalSourceTokensService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExternalSourceTokensServiceTest extends TestCase
{
    use RefreshDatabase;

    private ExternalSourceTokensService $service;

    private ExternalSource $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->source = ExternalSource::create(['name' => 'test_source']);
        $this->service = app(ExternalSourceTokensService::class);
    }

    public function test_creates_one_time_raw_token_and_stores_only_hash_and_hint(): void
    {
        $raw = $this->service->setAccessToken($this->source->id);
        $stored = ExternalSourceAccessTokens::sole();

        $this->assertStringStartsWith('ext_', $raw);
        $this->assertSame(68, strlen($raw));
        $this->assertNull($stored->token);
        $this->assertSame(hash('sha256', $raw), $stored->token_hash);
        $this->assertSame(substr($raw, -6), $stored->token_hint);
        $this->assertTrue($stored->active);
    }

    public function test_rotation_creates_new_token_and_gives_previous_token_24_hour_window(): void
    {
        $firstRaw = $this->service->setAccessToken($this->source->id);
        $first = ExternalSourceAccessTokens::sole();

        $secondRaw = $this->service->setAccessToken($this->source->id);
        $first->refresh();

        $this->assertNotSame($firstRaw, $secondRaw);
        $this->assertCount(2, ExternalSourceAccessTokens::all());
        $this->assertNotNull($first->expires_at);
        $this->assertTrue($first->expires_at->between(now()->addHours(23), now()->addHours(25)));
    }

    public function test_revoke_disables_token_immediately(): void
    {
        $this->service->setAccessToken($this->source->id);
        $token = ExternalSourceAccessTokens::sole();

        $this->assertTrue($this->service->revoke($token->id, $this->source->id));
        $this->assertNotNull($token->fresh()?->revoked_at);
        $this->assertFalse((bool) $token->fresh()?->active);
    }

    public function test_throws_exception_when_source_not_found(): void
    {
        $this->expectException(\Throwable::class);

        $this->service->setAccessToken(999999);
    }
}

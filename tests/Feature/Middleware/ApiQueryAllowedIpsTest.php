<?php

namespace Tests\Feature\Middleware;

use App\Models\ExternalSource;
use App\Models\ExternalSourceAccessTokens;
use App\Modules\External\Middleware\ApiQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class ApiQueryAllowedIpsTest extends TestCase
{
    use RefreshDatabase;

    private function makeRequest(string $token, string $ip): Request
    {
        $request = Request::create('/api/external/messages', 'GET');
        $request->headers->set('Authorization', 'Bearer ' . $token);
        $request->server->set('REMOTE_ADDR', $ip);

        return $request;
    }

    private function tokenFor(ExternalSource $source): string
    {
        $value = str_repeat('a', 64);

        ExternalSourceAccessTokens::create([
            'external_source_id' => $source->id,
            'token' => $value,
            'active' => true,
        ]);

        return $value;
    }

    public function test_allows_request_from_listed_ip(): void
    {
        $source = ExternalSource::factory()->create(['allowed_ips' => ['203.0.113.10']]);
        $token = $this->tokenFor($source);

        $response = (new ApiQuery())->handle(
            $this->makeRequest($token, '203.0.113.10'),
            fn () => response('ok')
        );

        $this->assertSame('ok', $response->getContent());
    }

    public function test_rejects_request_from_unlisted_ip(): void
    {
        $source = ExternalSource::factory()->create(['allowed_ips' => ['203.0.113.10']]);
        $token = $this->tokenFor($source);

        $response = (new ApiQuery())->handle(
            $this->makeRequest($token, '5.6.7.8'),
            fn () => response('ok')
        );

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function test_allows_any_ip_when_allowlist_empty(): void
    {
        $source = ExternalSource::factory()->create(['allowed_ips' => null]);
        $token = $this->tokenFor($source);

        $response = (new ApiQuery())->handle(
            $this->makeRequest($token, '5.6.7.8'),
            fn () => response('ok')
        );

        $this->assertSame('ok', $response->getContent());
    }

    public function test_rejects_expired_revoked_and_inactive_tokens(): void
    {
        $source = ExternalSource::factory()->create();

        foreach ([
            ['expires_at' => now()->subSecond()],
            ['revoked_at' => now(), 'active' => false],
            ['active' => false],
        ] as $state) {
            $raw = 'ext_' . bin2hex(random_bytes(32));
            ExternalSourceAccessTokens::create([
                'external_source_id' => $source->id,
                'token_hash' => hash('sha256', $raw),
                'token_hint' => substr($raw, -6),
                'active' => true,
                ...$state,
            ]);

            $response = (new ApiQuery())->handle($this->makeRequest($raw, '5.6.7.8'), fn () => response('ok'));
            $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        }
    }

    public function test_authentication_uses_hash_and_throttles_last_used_updates(): void
    {
        $source = ExternalSource::factory()->create();
        $raw = $this->tokenFor($source);
        $token = ExternalSourceAccessTokens::where('external_source_id', $source->id)->sole();

        (new ApiQuery())->handle($this->makeRequest($raw, '5.6.7.8'), fn () => response('ok'));
        $firstUsedAt = $token->fresh()?->last_used_at;
        $this->assertNotNull($firstUsedAt);

        $token->forceFill(['last_used_at' => now()->subMinutes(6)])->saveQuietly();
        (new ApiQuery())->handle($this->makeRequest($raw, '5.6.7.8'), fn () => response('ok'));
        $this->assertTrue($token->fresh()->last_used_at->gt(now()->subMinute()));
    }
}

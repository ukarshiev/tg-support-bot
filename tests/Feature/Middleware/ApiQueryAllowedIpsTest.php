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
}

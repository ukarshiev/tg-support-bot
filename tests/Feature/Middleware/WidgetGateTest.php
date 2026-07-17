<?php

namespace Tests\Feature\Middleware;

use App\Models\ExternalSource;
use App\Modules\External\Middleware\WidgetGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class WidgetGateTest extends TestCase
{
    use RefreshDatabase;

    private ExternalSource $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->source = ExternalSource::factory()->create();
    }

    private function token(string $externalId = 'user1', string $origin = 'https://client.example', ?int $expiresAt = null): string
    {
        return Crypt::encryptString(json_encode([
            'source_id' => $this->source->id,
            'external_id' => $externalId,
            'origin' => $origin,
            'expires_at' => $expiresAt ?? now()->addHour()->timestamp,
        ], JSON_THROW_ON_ERROR));
    }

    private function request(string $method = 'POST', string $externalId = 'user1', string $origin = 'https://client.example', ?string $token = null): Request
    {
        $request = Request::create("/api/widget/{$externalId}/messages", $method);
        $request->headers->set('Origin', $origin);

        if ($token !== null) {
            $request->headers->set('X-Widget-Token', $token);
        }

        $route = new Route([$method], '/api/widget/{external_id}/messages', fn () => null);
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);

        return $request;
    }

    private function runGate(Request $request, bool $expectNext = false): Response
    {
        $called = false;
        $response = (new WidgetGate())->handle($request, function () use (&$called) {
            $called = true;

            return response('ok');
        });

        $this->assertSame($expectNext, $called);

        return $response;
    }

    public function test_missing_token_and_legacy_key_are_rejected(): void
    {
        $request = $this->request();
        $request->headers->set('X-Widget-Key', 'legacy-key');

        $this->assertSame(401, $this->runGate($request)->getStatusCode());
    }

    public function test_valid_session_is_accepted_and_source_is_attached(): void
    {
        $request = $this->request(token: $this->token());
        $attached = null;

        $response = (new WidgetGate())->handle($request, function (Request $request) use (&$attached) {
            $attached = $request->attributes->get('widget_source');

            return response('ok');
        });

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($this->source->id, $attached?->id);
    }

    public function test_session_is_bound_to_external_id_origin_and_expiry(): void
    {
        $token = $this->token();

        $this->assertSame(401, $this->runGate($this->request(externalId: 'other', token: $token))->getStatusCode());
        $this->assertSame(401, $this->runGate($this->request(origin: 'https://evil.example', token: $token))->getStatusCode());
        $this->assertSame(401, $this->runGate($this->request(token: $this->token(expiresAt: now()->subSecond()->timestamp)))->getStatusCode());
    }

    public function test_options_preflight_returns_cors_without_authentication(): void
    {
        $response = $this->runGate($this->request(method: 'OPTIONS'));

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('https://client.example', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertSame('X-Widget-Token, Content-Type', $response->headers->get('Access-Control-Allow-Headers'));
    }

    public function test_denial_has_cors_headers(): void
    {
        $response = $this->runGate($this->request());

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('https://client.example', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_rate_limit_uses_session_identity(): void
    {
        $token = $this->token();
        $key = 'widget-send:' . $this->source->id . ':' . hash('sha256', $token);
        RateLimiter::clear($key);

        for ($i = 0; $i < 30; $i++) {
            RateLimiter::hit($key, 60);
        }

        $this->assertSame(429, $this->runGate($this->request(token: $token))->getStatusCode());
    }
}

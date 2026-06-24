<?php

namespace Tests\Feature\Middleware;

use App\Models\ExternalSource;
use App\Modules\External\Middleware\WidgetGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class WidgetGateTest extends TestCase
{
    use RefreshDatabase;

    private function makeRequest(
        string $key = '',
        string $ip = '1.2.3.4',
        string $origin = '',
        string $method = 'POST',
    ): Request {
        $request = Request::create('/api/widget/user1/messages', $method);
        $request->server->set('REMOTE_ADDR', $ip);

        if ($key !== '') {
            $request->headers->set('X-Widget-Key', $key);
        }

        if ($origin !== '') {
            $request->headers->set('Origin', $origin);
        }

        return $request;
    }

    private function runMiddleware(Request $request, bool $nextCalled = false): \Symfony\Component\HttpFoundation\Response
    {
        $called = false;

        $response = (new WidgetGate())->handle($request, function () use (&$called) {
            $called = true;

            return response('ok');
        });

        if ($nextCalled) {
            $this->assertTrue($called, 'Expected next() to be called but it was not.');
        }

        return $response;
    }

    // ── Missing key ───────────────────────────────────────────────────────────

    public function test_returns_401_when_key_header_absent(): void
    {
        $response = $this->runMiddleware($this->makeRequest(key: ''));

        $this->assertSame(401, $response->getStatusCode());
    }

    // ── Invalid key ───────────────────────────────────────────────────────────

    public function test_returns_401_when_key_not_found_in_db(): void
    {
        $response = $this->runMiddleware($this->makeRequest(key: 'pub_unknown'));

        $this->assertSame(401, $response->getStatusCode());
    }

    // ── Allowlist behaviour variants ─────────────────────────────────────────

    public function test_key_with_empty_allowlist_allows_any_origin(): void
    {
        $source = ExternalSource::factory()->create([
            'public_key' => 'pub_' . str_repeat('g', 36),
            'allowed_ips' => null,
        ]);

        $response = $this->runMiddleware(
            $this->makeRequest(key: $source->public_key, ip: '8.8.8.8', origin: 'https://anything.example'),
            nextCalled: true
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_key_with_domain_allowlist_rejects_wrong_origin(): void
    {
        $source = ExternalSource::factory()->create([
            'public_key' => 'pub_' . str_repeat('h', 36),
            'allowed_ips' => ['example.com'],
        ]);

        $response = $this->runMiddleware(
            $this->makeRequest(key: $source->public_key, ip: '1.2.3.4', origin: 'https://other.com')
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_key_with_domain_allowlist_allows_matching_origin(): void
    {
        $source = ExternalSource::factory()->create([
            'public_key' => 'pub_' . str_repeat('i', 36),
            'allowed_ips' => ['example.com'],
        ]);

        $response = $this->runMiddleware(
            $this->makeRequest(key: $source->public_key, ip: '1.2.3.4', origin: 'https://example.com'),
            nextCalled: true
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    // ── IP restriction ────────────────────────────────────────────────────────

    public function test_returns_403_when_ip_not_in_allowlist(): void
    {
        $source = ExternalSource::factory()->create([
            'public_key' => 'pub_' . str_repeat('a', 36),
            'allowed_ips' => ['203.0.113.10'],
        ]);

        $response = $this->runMiddleware(
            $this->makeRequest(key: $source->public_key, ip: '9.9.9.9')
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    // ── Happy path ────────────────────────────────────────────────────────────

    public function test_passes_request_when_key_valid_and_ip_allowed(): void
    {
        $source = ExternalSource::factory()->create([
            'public_key' => 'pub_' . str_repeat('b', 36),
            'allowed_ips' => null,
        ]);

        $response = $this->runMiddleware(
            $this->makeRequest(key: $source->public_key, ip: '1.2.3.4'),
            nextCalled: true
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    // ── CORS headers ──────────────────────────────────────────────────────────

    public function test_cors_headers_set_on_successful_request(): void
    {
        $source = ExternalSource::factory()->create([
            'public_key' => 'pub_' . str_repeat('c', 36),
            'allowed_ips' => null,
        ]);

        $request = $this->makeRequest(key: $source->public_key, origin: 'https://example.com');
        $response = $this->runMiddleware($request);

        $this->assertSame('https://example.com', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotEmpty($response->headers->get('Access-Control-Allow-Methods'));
    }

    // ── OPTIONS preflight ────────────────────────────────────────────────────

    public function test_options_preflight_returns_204_with_cors_headers(): void
    {
        $source = ExternalSource::factory()->create([
            'public_key' => 'pub_' . str_repeat('d', 36),
            'allowed_ips' => null,
        ]);

        $request = $this->makeRequest(
            key: $source->public_key,
            origin: 'https://example.com',
            method: 'OPTIONS'
        );

        $nextCalled = false;
        $response = (new WidgetGate())->handle($request, function () use (&$nextCalled) {
            $nextCalled = true;

            return response('ok');
        });

        $this->assertSame(204, $response->getStatusCode());
        $this->assertFalse($nextCalled, 'next() must not be called for OPTIONS preflight');
        $this->assertNotEmpty($response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_options_preflight_without_widget_key_still_returns_204_with_cors(): void
    {
        // Browsers never send X-Widget-Key on the CORS preflight, so the gate
        // must answer OPTIONS before the key check — otherwise the preflight is
        // rejected (401, no CORS) and the real request is blocked by the browser.
        $request = $this->makeRequest(
            key: '',
            origin: 'https://example.com',
            method: 'OPTIONS'
        );

        $nextCalled = false;
        $response = (new WidgetGate())->handle($request, function () use (&$nextCalled) {
            $nextCalled = true;

            return response('ok');
        });

        $this->assertSame(204, $response->getStatusCode());
        $this->assertFalse($nextCalled);
        $this->assertSame('https://example.com', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotEmpty($response->headers->get('Access-Control-Allow-Headers'));
    }

    public function test_denied_responses_carry_cors_headers(): void
    {
        // 401/403 must include CORS headers, otherwise the browser masks the
        // real status as a generic "CORS error".
        $request = $this->makeRequest(key: 'pub_unknown', origin: 'https://example.com');

        $response = (new WidgetGate())->handle($request, fn () => response('ok'));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('https://example.com', $response->headers->get('Access-Control-Allow-Origin'));
    }

    // ── Rate limiting ────────────────────────────────────────────────────────

    public function test_returns_429_when_rate_limit_exceeded(): void
    {
        $source = ExternalSource::factory()->create([
            'public_key' => 'pub_' . str_repeat('e', 36),
            'allowed_ips' => null,
        ]);

        $key = $source->public_key;
        $rateLimitKey = 'widget-send:' . $key . ':1.2.3.4';

        // Exhaust the limit manually
        RateLimiter::clear($rateLimitKey);

        for ($i = 0; $i < 30; $i++) {
            RateLimiter::hit($rateLimitKey, 60);
        }

        $response = $this->runMiddleware(
            $this->makeRequest(key: $key, ip: '1.2.3.4', method: 'POST')
        );

        $this->assertSame(429, $response->getStatusCode());
    }

    // ── Source attached to request ────────────────────────────────────────────

    public function test_attaches_source_to_request_attributes(): void
    {
        $source = ExternalSource::factory()->create([
            'public_key' => 'pub_' . str_repeat('f', 36),
            'allowed_ips' => null,
        ]);

        $request = $this->makeRequest(key: $source->public_key);
        $attachedSource = null;

        (new WidgetGate())->handle($request, function (Request $r) use (&$attachedSource) {
            $attachedSource = $r->attributes->get('widget_source');

            return response('ok');
        });

        $this->assertNotNull($attachedSource);
        $this->assertSame($source->id, $attachedSource->id);
    }
}

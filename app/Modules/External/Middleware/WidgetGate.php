<?php

declare(strict_types=1);

namespace App\Modules\External\Middleware;

use App\Models\ExternalSource;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * WidgetGate middleware — authenticates and rate-limits JS widget requests.
 *
 * Flow:
 *   1. Read X-Widget-Key header.
 *   2. Look up ExternalSource by public_key.
 *   3. Check domain/IP allowlist via ExternalSource::isRequestAllowed().
 *   4. Apply rate limits:
 *        POST routes  → widget-send:{key}:{ip}   30 req/min
 *        GET routes   → widget-poll:{key}:{ip}  120 req/min
 *   5. Add CORS headers to the response.
 *   6. Handle OPTIONS preflight (204 + CORS headers, no business logic).
 *   7. Attach the ExternalSource to request attributes as 'widget_source'.
 *
 * Security note: the public key is NEVER logged — only its first lookup
 * determines access; once found we refer to the source by id/name.
 */
class WidgetGate
{
    public function handle(Request $request, Closure $next): Response
    {
        // CORS preflight: the browser sends OPTIONS WITHOUT the X-Widget-Key
        // header (and without credentials), so it must be answered before any
        // auth/allowlist check — otherwise the preflight is rejected and the
        // real request never runs.
        if ($request->isMethod('OPTIONS')) {
            return $this->corsResponse($request, response('', 204));
        }

        $key = (string) $request->header('X-Widget-Key', '');

        if ($key === '') {
            return $this->corsResponse($request, $this->deny(401, 'Widget key required.'));
        }

        /** @var ExternalSource|null $source */
        $source = ExternalSource::where('public_key', $key)->first();

        if (! $source) {
            return $this->corsResponse($request, $this->deny(401, 'Invalid widget key.'));
        }

        if (! $source->isRequestAllowed($request)) {
            return $this->corsResponse($request, $this->deny(403, 'Origin or IP not allowed.'));
        }

        // Rate limiting: POST = send, GET = poll
        if ($request->isMethod('POST')) {
            $rateLimitKey = 'widget-send:' . $key . ':' . ($request->ip() ?? '');
            $maxAttempts = 30;
        } else {
            $rateLimitKey = 'widget-poll:' . $key . ':' . ($request->ip() ?? '');
            $maxAttempts = 120;
        }

        if (RateLimiter::tooManyAttempts($rateLimitKey, $maxAttempts)) {
            return $this->corsResponse(
                $request,
                $this->deny(429, 'Too many requests.')
            );
        }

        RateLimiter::hit($rateLimitKey, 60);

        $request->attributes->set('widget_source', $source);

        /** @var Response $response */
        $response = $next($request);

        return $this->corsResponse($request, $response);
    }

    /**
     * Add CORS headers to the response.
     *
     * Access-Control-Allow-Origin is set to the request's Origin header value
     * (only if the request was already allowed through isRequestAllowed()).
     *
     * @param Request  $request
     * @param Response $response
     *
     * @return Response
     */
    private function corsResponse(Request $request, Response $response): Response
    {
        $origin = (string) $request->header('Origin', '');

        if ($origin !== '') {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
        }

        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'X-Widget-Key, Content-Type');
        $response->headers->set('Access-Control-Max-Age', '86400');
        $response->headers->set('Vary', 'Origin');

        return $response;
    }

    /**
     * Return a JSON error response (without CORS headers — caller adds them if needed).
     *
     * @param int    $status
     * @param string $message
     *
     * @return Response
     */
    private function deny(int $status, string $message): Response
    {
        return response()->json(['message' => $message], $status);
    }
}

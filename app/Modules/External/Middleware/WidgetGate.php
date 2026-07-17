<?php

declare(strict_types=1);

namespace App\Modules\External\Middleware;

use App\Models\ExternalSource;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * WidgetGate middleware — authenticates and rate-limits JS widget requests.
 *
 * Flow:
 * Authenticates short-lived backend-issued widget sessions, applies rate
 * limits, and attaches the scoped ExternalSource to the request.
 */
class WidgetGate
{
    public function handle(Request $request, Closure $next): Response
    {
        // CORS preflight: the browser sends OPTIONS without the session token
        // header (and without credentials), so it must be answered before any
        // auth/allowlist check — otherwise the preflight is rejected and the
        // real request never runs.
        if ($request->isMethod('OPTIONS')) {
            return $this->corsResponse($request, response('', 204));
        }

        $sessionToken = (string) $request->header('X-Widget-Token', '');
        if ($sessionToken === '') {
            return $this->corsResponse($request, $this->deny(401, 'Widget session required.'));
        }

        $source = $this->sourceFromSession($request, $sessionToken);
        if ($source === null) {
            return $this->corsResponse($request, $this->deny(401, 'Invalid widget session.'));
        }

        return $this->handleAuthorized($request, $next, $source, hash('sha256', $sessionToken));
    }

    private function handleAuthorized(Request $request, Closure $next, ExternalSource $source, string $identity): Response
    {
        // Rate limiting: POST = send, GET = poll
        if ($request->isMethod('POST')) {
            $rateLimitKey = 'widget-send:' . $source->id . ':' . $identity;
            $maxAttempts = 30;
        } else {
            $rateLimitKey = 'widget-poll:' . $source->id . ':' . $identity;
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

    private function sourceFromSession(Request $request, string $token): ?ExternalSource
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
            $routeExternalId = (string) $request->route('external_id');
            $requestOrigin = $this->normalizeOrigin((string) $request->header('Origin', ''));

            if (! is_array($payload)
                || ! isset($payload['source_id'], $payload['external_id'], $payload['origin'], $payload['expires_at'])
                || ! hash_equals((string) $payload['external_id'], $routeExternalId)
                || (int) $payload['expires_at'] < now()->timestamp
                || $requestOrigin === ''
                || ! hash_equals((string) $payload['origin'], $requestOrigin)) {
                return null;
            }

            return ExternalSource::find((int) $payload['source_id']);
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeOrigin(string $origin): string
    {
        $parts = parse_url($origin);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        return strtolower((string) $parts['scheme']) . '://'
            . strtolower((string) $parts['host'])
            . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /**
     * Add CORS headers to the response.
     *
     * Access-Control-Allow-Origin is set to the request's Origin header value
     * for both successful and denied browser requests.
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
        $response->headers->set('Access-Control-Allow-Headers', 'X-Widget-Token, Content-Type');
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

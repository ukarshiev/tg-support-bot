<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate the Telescope dashboard (/telescope).
 *
 * Access requires BOTH:
 *  - APP_DEBUG=true — Telescope returns 404 (hidden) on non-debug builds;
 *  - HTTP Basic auth matching the credentials in env
 *    (TELESCOPE_AUTH_USER / TELESCOPE_AUTH_PASSWORD), exposed via
 *    config('telescope.basic_auth.*').
 *
 * Fails closed: if the credentials are not configured, access is denied (403).
 * Credentials are compared with hash_equals() (timing-safe) and never logged.
 */
class TelescopeBasicAuth
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     *
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Telescope is reachable only on debug builds.
        if (config('app.debug') !== true) {
            abort(404);
        }

        $username = (string) config('telescope.basic_auth.username', '');
        $password = (string) config('telescope.basic_auth.password', '');

        // Fail closed when credentials are not configured.
        if ($username === '' || $password === '') {
            abort(403, 'Доступ к Telescope не настроен.');
        }

        $providedUser = (string) $request->getUser();
        $providedPassword = (string) $request->getPassword();

        $ok = hash_equals($username, $providedUser)
            && hash_equals($password, $providedPassword);

        if (! $ok) {
            return response('Unauthorized.', Response::HTTP_UNAUTHORIZED, [
                'WWW-Authenticate' => 'Basic realm="Telescope"',
            ]);
        }

        return $next($request);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate the Telescope dashboard (/telescope) by session-based admin access.
 *
 * Replaces the former HTTP Basic auth gate: a Basic-auth 401 challenge is
 * intercepted/stripped by the upstream edge proxy in front of the production
 * domain (it rewrites 4xx responses and drops `WWW-Authenticate`), so the login
 * form never reached the browser. Session auth avoids any 401 challenge — guests
 * are redirected to /admin/login by the `auth` middleware (302, passes the edge)
 * and only authenticated admins reach the dashboard.
 *
 * Runs after `['web', 'auth']` in the Telescope middleware stack, so by the time
 * this runs the visitor is authenticated; here we only enforce the admin role.
 */
class TelescopeAccess
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
        $user = $request->user();

        if (! $user instanceof User || ! $user->isAdmin()) {
            abort(403);
        }

        return $next($request);
    }
}

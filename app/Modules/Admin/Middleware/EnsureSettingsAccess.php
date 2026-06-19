<?php

declare(strict_types=1);

namespace App\Modules\Admin\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts access to the /admin/settings/* screens by role.
 *
 * - Admins have full access to every settings screen.
 * - Managers may only open «Основные» (admin.settings.general), where they
 *   see the notifications card only; every other settings route redirects
 *   them back to «Основные».
 *
 * Authentication itself is handled by the Filament Authenticate middleware,
 * which runs earlier in the group — by the time this runs the user is signed in.
 */
class EnsureSettingsAccess
{
    /**
     * @param Request                                                        $request
     * @param Closure(Request): (\Symfony\Component\HttpFoundation\Response) $next
     *
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user && ($user->isAdmin() || $request->routeIs('admin.settings.general'))) {
            return $next($request);
        }

        return redirect()->route('admin.settings.general');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Enums\UserRole;
use App\Http\Middleware\TelescopeAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Unit tests for the Telescope access gate.
 *
 * Telescope is gated by session-based admin auth (the `auth` middleware handles
 * the guest → /admin/login redirect upstream; this middleware enforces the admin
 * role). HTTP Basic auth was dropped because the upstream edge proxy strips the
 * 401 `WWW-Authenticate` challenge on the production domain.
 */
class TelescopeAccessTest extends TestCase
{
    use RefreshDatabase;

    private function passThrough(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        return (new TelescopeAccess())->handle($request, fn () => response('ok', 200));
    }

    public function test_admin_is_allowed_through(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $request = Request::create('/telescope', 'GET');
        $request->setUserResolver(fn () => $admin);

        $response = $this->passThrough($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', $response->getContent());
    }

    public function test_manager_is_forbidden(): void
    {
        $manager = User::factory()->manager()->create();
        $request = Request::create('/telescope', 'GET');
        $request->setUserResolver(fn () => $manager);

        try {
            $this->passThrough($request);
            $this->fail('Expected a 403 HttpException for a non-admin user.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_guest_is_forbidden(): void
    {
        $request = Request::create('/telescope', 'GET');
        $request->setUserResolver(fn () => null);

        try {
            $this->passThrough($request);
            $this->fail('Expected a 403 HttpException for a guest.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }
}

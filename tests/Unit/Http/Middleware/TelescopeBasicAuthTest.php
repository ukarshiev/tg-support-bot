<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\TelescopeBasicAuth;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class TelescopeBasicAuthTest extends TestCase
{
    private function handle(Request $request): Response
    {
        return (new TelescopeBasicAuth())->handle($request, fn () => new Response('OK', 200));
    }

    private function request(?string $user = null, ?string $password = null): Request
    {
        $server = [];

        if ($user !== null) {
            $server['PHP_AUTH_USER'] = $user;
            $server['PHP_AUTH_PW'] = (string) $password;
        }

        return Request::create('/telescope', 'GET', [], [], [], $server);
    }

    public function test_returns_404_when_app_debug_is_off(): void
    {
        config(['app.debug' => false]);

        try {
            $this->handle($this->request('admin', 'secret'));
            $this->fail('Expected an HTTP 404.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    public function test_returns_403_when_credentials_are_not_configured(): void
    {
        config([
            'app.debug' => true,
            'telescope.basic_auth.username' => '',
            'telescope.basic_auth.password' => '',
        ]);

        try {
            $this->handle($this->request('admin', 'secret'));
            $this->fail('Expected an HTTP 403.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_returns_401_without_credentials(): void
    {
        config([
            'app.debug' => true,
            'telescope.basic_auth.username' => 'admin',
            'telescope.basic_auth.password' => 'secret',
        ]);

        $response = $this->handle($this->request());

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('Basic realm="Telescope"', $response->headers->get('WWW-Authenticate'));
    }

    public function test_returns_401_with_wrong_credentials(): void
    {
        config([
            'app.debug' => true,
            'telescope.basic_auth.username' => 'admin',
            'telescope.basic_auth.password' => 'secret',
        ]);

        $response = $this->handle($this->request('admin', 'wrong'));

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_passes_with_correct_credentials(): void
    {
        config([
            'app.debug' => true,
            'telescope.basic_auth.username' => 'admin',
            'telescope.basic_auth.password' => 'secret',
        ]);

        $response = $this->handle($this->request('admin', 'secret'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('OK', $response->getContent());
    }
}

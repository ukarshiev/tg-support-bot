<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Trust all proxies so that $request->ip() returns the real client IP
        // when the app runs behind a reverse proxy (nginx / Docker / cloud LB).
        // Security trade-off: trusting all proxies means a client with direct
        // access could spoof X-Forwarded-For. This is acceptable because all
        // production traffic is routed through a trusted nginx reverse proxy and
        // direct external access to the application container is blocked.
        $trustedProxies = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('TRUSTED_PROXIES', '127.0.0.1,172.16.0.0/12')),
        )));
        $middleware->trustProxies(at: $trustedProxies);

        // Auth redirects for the admin area (replaces Filament's panel routing):
        // - unauthenticated visitors → the login screen;
        // - already-authenticated visitors hitting guest-only routes → chat workspace.
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('admin.chats'));
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (RouteNotFoundException $e, Request $request) {
            return response()->json([
                'message' => 'Route not found.',
            ], 404);
        });

        /**
         * Log unhandled exceptions to the application log file channel.
         */
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($e instanceof HttpExceptionInterface || $e instanceof RouteNotFoundException || $e instanceof AuthenticationException) {
                return null;
            }

            Log::channel('app')->error('File: ' . $e->getFile() . '; Line: ' . $e->getLine() . '; Error: ' . $e->getMessage());

            // Returning a successful response here hides every application error,
            // including validation failures, and makes callers acknowledge failed
            // webhooks as processed. Let Laravel render the original exception so
            // that its HTTP status and standard JSON error shape are preserved.
            return null;
        });
    })->create();

<?php

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
        $middleware->trustProxies(at: '*');
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
            if ($e instanceof HttpExceptionInterface || $e instanceof RouteNotFoundException) {
                return null;
            }

            Log::channel('app')->error('File: ' . $e->getFile() . '; Line: ' . $e->getLine() . '; Error: ' . $e->getMessage());

            if (env('APP_DEBUG') === false) {
                return response('ok', 200);
            }
        });
    })->create();

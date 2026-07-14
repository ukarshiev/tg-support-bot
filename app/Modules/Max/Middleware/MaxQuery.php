<?php

namespace App\Modules\Max\Middleware;

use App\Services\Settings\SettingsService;
use App\Support\InboundWebhookLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class MaxQuery
{
    /**
     * Handle an incoming request.
     *
     * @param \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response) $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $secretCode = (string) app(SettingsService::class)->get('max.secret_key');
            if ($secretCode !== $request->header('X-Max-Bot-Api-Secret')) {
                throw new \RuntimeException('Secret-Key is invalid!');
            }

            $this->logRequest($request);

            return $next($request);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Access is forbidden',
                'error' => $e->getMessage(),
            ], Response::HTTP_FORBIDDEN);
        }
    }

    /**
     * @param Request $request
     *
     * @return void
     */
    private function logRequest(Request $request): void
    {
        Log::channel('app')->info(
            'MAX webhook accepted',
            InboundWebhookLog::summarize('max', $request->all()),
        );
    }
}

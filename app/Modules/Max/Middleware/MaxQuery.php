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
        $secretCode = (string) app(SettingsService::class)->get('max.secret_key');
        if ($secretCode === '') {
            Log::channel('app')->critical('MAX webhook secret is not configured');

            return response()->json(['message' => 'Webhook is unavailable'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $receivedSecret = (string) $request->header('X-Max-Bot-Api-Secret', '');
        if ($receivedSecret === '' || ! hash_equals($secretCode, $receivedSecret)) {
            return response()->json(['message' => 'Access is forbidden'], Response::HTTP_FORBIDDEN);
        }

        $this->logRequest($request);

        return $next($request);
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

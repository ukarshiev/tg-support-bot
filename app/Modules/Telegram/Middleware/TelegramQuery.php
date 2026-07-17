<?php

namespace App\Modules\Telegram\Middleware;

use App\Services\Settings\SettingsService;
use App\Support\InboundWebhookLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class TelegramQuery
{
    /**
     * Handle an incoming request.
     *
     * @param \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response) $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $receivedToken = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');
        $secretKey = (string) app(SettingsService::class)->get('telegram.secret_key');

        if ($receivedToken === '' || $secretKey === '' || ! hash_equals($secretKey, $receivedToken)) {
            return response()->json([
                'message' => 'Access is forbidden',
            ], Response::HTTP_FORBIDDEN);
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
            'Telegram webhook accepted',
            InboundWebhookLog::summarize('telegram', $request->all()),
        );
    }
}

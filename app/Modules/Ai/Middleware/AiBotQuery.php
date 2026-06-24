<?php

namespace App\Modules\Ai\Middleware;

use App\Services\Settings\SettingsService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AiBotQuery
{
    /**
     * Handle an incoming request.
     *
     * Validates the X-Telegram-Bot-Api-Secret-Token header against
     * the telegram_ai.secret setting stored in the DB via SettingsService.
     *
     * @param Request                                                                          $request
     * @param \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response) $next
     *
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $receivedToken = $request->header('X-Telegram-Bot-Api-Secret-Token');
            if (empty($receivedToken)) {
                throw new \RuntimeException('Secret-Token is missing!');
            }

            $secret = (string) app(SettingsService::class)->get('telegram_ai.secret');
            if ($receivedToken !== $secret) {
                throw new \RuntimeException('Secret-Token is invalid!');
            }

            Log::channel('app')->info(json_encode($request->all()), ['source' => 'ai_bot_request']);

            return $next($request);
        } catch (\Throwable $e) {
            Log::channel('app')->warning('AiBotQuery: rejected with 403', [
                'source' => 'ai_bot_forbidden',
                'reason' => $e->getMessage(),
                'has_secret_header' => $request->hasHeader('X-Telegram-Bot-Api-Secret-Token'),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json([
                'message' => 'Access is forbidden',
            ], Response::HTTP_FORBIDDEN);
        }
    }
}

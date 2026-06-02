<?php

namespace App\Modules\Telegram\Middleware;

use App\Services\Settings\SettingsService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Mockery\Exception;
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
        try {
            $receivedToken = $request->header('X-Telegram-Bot-Api-Secret-Token');
            if (empty($receivedToken)) {
                throw new Exception('Secret-Token is invalid!');
            }

            $secretKey = (string) app(SettingsService::class)->get('telegram.secret_key');
            if ($receivedToken !== $secretKey) {
                throw new Exception('Secret-Token is invalid!');
            }

            $this->sendRequestInLoki($request);
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
    private function sendRequestInLoki(Request $request): void
    {
        Log::channel('loki')->info(json_encode($request->all()), ['source' => 'tg_request']);
    }
}

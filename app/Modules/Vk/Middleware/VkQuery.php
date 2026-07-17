<?php

namespace App\Modules\Vk\Middleware;

use App\Services\Settings\SettingsService;
use App\Support\InboundWebhookLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VkQuery
{
    /**
     * Handle an incoming request.
     *
     * @param \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response) $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $settings = app(SettingsService::class);
        $secretCode = (string) $settings->get('vk.secret_key');
        if ($secretCode === '') {
            Log::channel('app')->critical('VK webhook secret is not configured');

            return response()->json(['message' => 'Webhook is unavailable'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $receivedSecret = (string) $request->input('secret', '');
        if ($receivedSecret === '' || ! hash_equals($secretCode, $receivedSecret)) {
            return response()->json(['message' => 'Access is forbidden'], Response::HTTP_FORBIDDEN);
        }

        $configuredGroupId = (int) ($settings->get('vk.group_id') ?? 0);
        $receivedGroupId = (int) $request->input('group_id', 0);
        if ($configuredGroupId > 0 && $receivedGroupId !== $configuredGroupId) {
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
            'VK webhook accepted',
            InboundWebhookLog::summarize('vk', $request->all()),
        );
    }
}

<?php

namespace App\Modules\External\Middleware;

use App\Models\ExternalSourceAccessTokens;
use App\Support\InboundWebhookLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Mockery\Exception;
use Symfony\Component\HttpFoundation\Response;

class ApiQuery
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $token = $request->bearerToken();
            if (empty($token)) {
                throw new Exception('Bearer Token not found!');
            }

            $itemAccessToken = ExternalSourceAccessTokens::where('token', $token)
                ->with([
                    'external_source',
                ])
                ->first();

            if (!$itemAccessToken) {
                throw new Exception('Bearer Token is invalid!');
            }

            $externalSource = $itemAccessToken->external_source;

            if (!$externalSource->isIpAllowed($request->ip())) {
                throw new Exception('Request IP is not allowed for this source!');
            }

            $request->merge([
                'source' => $externalSource->name,
                'external_id' => $request->route('external_id') ?? null,
            ]);

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
        Log::channel('app')->info('Входящий запрос внешнего канала принят', [
            'source' => 'api_request',
            ...InboundWebhookLog::summarize('external', $request->all()),
        ]);
    }
}

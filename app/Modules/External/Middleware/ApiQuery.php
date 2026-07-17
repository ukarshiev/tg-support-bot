<?php

namespace App\Modules\External\Middleware;

use App\Models\ExternalSourceAccessTokens;
use App\Support\InboundWebhookLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ApiQuery
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) ($request->bearerToken() ?? '');
        if ($token === '') {
            return $this->deny(Response::HTTP_UNAUTHORIZED);
        }

        $itemAccessToken = ExternalSourceAccessTokens::where('token_hash', hash('sha256', $token))
            ->where('active', true)
            ->whereNull('revoked_at')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->with('external_source')
            ->first();

        if (! $itemAccessToken) {
            return $this->deny(Response::HTTP_UNAUTHORIZED);
        }

        $externalSource = $itemAccessToken->external_source;

        if (! $externalSource->isIpAllowed($request->ip())) {
            return $this->deny(Response::HTTP_FORBIDDEN);
        }

        $request->merge([
            'source' => $externalSource->name,
            'external_id' => $request->route('external_id') ?? null,
        ]);
        $request->attributes->set('external_source', $externalSource);

        if ($itemAccessToken->last_used_at === null || $itemAccessToken->last_used_at->lt(now()->subMinutes(5))) {
            $itemAccessToken->forceFill(['last_used_at' => now()])->saveQuietly();
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
        Log::channel('app')->info('Входящий запрос внешнего канала принят', [
            'source' => 'api_request',
            ...InboundWebhookLog::summarize('external', $request->all()),
        ]);
    }

    private function deny(int $status): Response
    {
        return response()->json(['message' => 'Access is forbidden'], $status);
    }
}

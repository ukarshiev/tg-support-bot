<?php

declare(strict_types=1);

namespace App\Modules\External\Controllers;

use App\Models\ExternalSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class WidgetSessionController
{
    public function store(Request $request, string $external_id): JsonResponse
    {
        $validated = $request->validate([
            'origin' => ['required', 'url', 'max:2048'],
        ]);

        /** @var ExternalSource|null $source */
        $source = $request->attributes->get('external_source');
        if ($source === null) {
            return response()->json(['message' => 'Access is forbidden'], 403);
        }

        $origin = $this->normalizeOrigin((string) $validated['origin']);
        $expiresAt = now()->addHour();
        $token = Crypt::encryptString(json_encode([
            'source_id' => $source->id,
            'external_id' => $external_id,
            'origin' => $origin,
            'expires_at' => $expiresAt->timestamp,
        ], JSON_THROW_ON_ERROR));

        return response()->json([
            'token' => $token,
            'expires_at' => $expiresAt->toIso8601String(),
        ]);
    }

    private function normalizeOrigin(string $origin): string
    {
        $parts = parse_url($origin);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return $scheme . '://' . $host . $port;
    }
}

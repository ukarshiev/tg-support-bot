<?php

declare(strict_types=1);

namespace App\Modules\Ai\Support;

use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SupportEmbeddingService
{
    /**
     * @return array<int, float>|null
     */
    public function embed(string $text): ?array
    {
        $settings = app(SettingsService::class);
        $apiKey = (string) ($settings->get('ai.openai_api_key') ?? '');
        $baseUrl = rtrim((string) ($settings->get('ai.openai_base_url') ?? 'https://api.openai.com/v1'), '/');
        $model = (string) ($settings->get('ai.openai_embedding_model') ?? 'text-embedding-3-small');

        if ($apiKey === '' || $baseUrl === '') {
            return null;
        }

        try {
            $response = Http::timeout(20)
                ->withToken($apiKey)
                ->post($baseUrl . '/embeddings', [
                    'model' => $model,
                    'input' => mb_substr($text, 0, 8000),
                ]);

            if (! $response->successful()) {
                Log::channel('app')->warning('Support embedding request failed', [
                    'source' => 'ai_support_embedding',
                    'status' => $response->status(),
                ]);

                return null;
            }

            $embedding = $response->json('data.0.embedding');
            if (! is_array($embedding)) {
                return null;
            }

            return array_map(static fn (mixed $value): float => (float) $value, $embedding);
        } catch (\Throwable $e) {
            Log::channel('app')->warning('Support embedding unavailable', [
                'source' => 'ai_support_embedding',
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}

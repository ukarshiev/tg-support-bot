<?php

namespace App\Modules\Translation\Providers;

use App\Modules\Translation\Contracts\TranslationProvider;
use App\Modules\Translation\DTOs\TranslationRequest;
use App\Modules\Translation\DTOs\TranslationResult;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Http;

class OfflineHttpTranslationProvider implements TranslationProvider
{
    public function __construct(private readonly SettingsService $settings)
    {
    }

    public function key(): string
    {
        return 'offline';
    }

    public function isExternal(): bool
    {
        return false;
    }

    public function translate(TranslationRequest $request): TranslationResult
    {
        $endpoint = trim((string) ($this->settings->get('translation.offline_endpoint') ?? ''));

        if ($endpoint === '') {
            return TranslationResult::failure('not_configured', 'Offline-переводчик не настроен.', $this->key());
        }

        try {
            $response = Http::timeout(20)->post(rtrim($endpoint, '/') . '/translate', [
                'source' => $request->sourceLocale,
                'target' => $request->targetLocale,
                'text' => $request->text,
            ]);

            if (!$response->successful()) {
                return TranslationResult::failure('provider_error', 'Offline Translate error: ' . $response->status(), $this->key());
            }

            $translated = $response->json('text');
            if (!is_string($translated) || $translated === '') {
                return TranslationResult::failure('empty_response', 'Offline-переводчик вернул пустой перевод.', $this->key());
            }

            return TranslationResult::success($translated, $this->key());
        } catch (\Throwable $e) {
            return TranslationResult::failure('timeout_or_network', $e->getMessage(), $this->key());
        }
    }
}

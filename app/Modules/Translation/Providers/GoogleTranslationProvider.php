<?php

namespace App\Modules\Translation\Providers;

use App\Modules\Translation\Contracts\TranslationProvider;
use App\Modules\Translation\DTOs\TranslationRequest;
use App\Modules\Translation\DTOs\TranslationResult;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Http;

class GoogleTranslationProvider implements TranslationProvider
{
    public function __construct(private readonly SettingsService $settings)
    {
    }

    public function key(): string
    {
        return 'google';
    }

    public function isExternal(): bool
    {
        return true;
    }

    public function translate(TranslationRequest $request): TranslationResult
    {
        $apiKey = (string) ($this->settings->get('translation.google_api_key') ?? '');

        if ($apiKey === '') {
            return TranslationResult::failure('not_configured', 'Google Translate не настроен.', $this->key());
        }

        try {
            $response = Http::timeout(12)
                ->asForm()
                ->post('https://translation.googleapis.com/language/translate/v2', [
                    'key' => $apiKey,
                    'q' => $request->text,
                    'source' => $request->sourceLocale,
                    'target' => $request->targetLocale,
                    'format' => 'text',
                ]);

            if ($response->status() === 429) {
                return TranslationResult::failure('rate_limited', 'Google Translate вернул 429.', $this->key());
            }

            if (!$response->successful()) {
                return TranslationResult::failure('provider_error', 'Google Translate error: ' . $response->status(), $this->key());
            }

            $translated = $response->json('data.translations.0.translatedText');
            if (!is_string($translated) || $translated === '') {
                return TranslationResult::failure('empty_response', 'Google Translate вернул пустой перевод.', $this->key());
            }

            return TranslationResult::success(html_entity_decode($translated, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $this->key());
        } catch (\Throwable $e) {
            return TranslationResult::failure('timeout_or_network', $e->getMessage(), $this->key());
        }
    }
}

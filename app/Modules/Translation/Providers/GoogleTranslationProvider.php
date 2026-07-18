<?php

namespace App\Modules\Translation\Providers;

use App\Modules\Translation\Contracts\BatchTranslationProvider;
use App\Modules\Translation\DTOs\TranslationRequest;
use App\Modules\Translation\DTOs\TranslationResult;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Http;

class GoogleTranslationProvider implements BatchTranslationProvider
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
        return $this->translateBatch($request, [$request->text])[0]
            ?? TranslationResult::failure('empty_response', 'Google Translate вернул пустой перевод.', $this->key());
    }

    public function translateBatch(TranslationRequest $request, array $texts): array
    {
        $apiKey = (string) ($this->settings->get('translation.google_api_key') ?? '');

        if ($apiKey === '') {
            return $this->failBatch($texts, 'not_configured', 'Google Translate не настроен.');
        }

        try {
            $payload = [
                'q' => array_values($texts),
                'target' => $request->targetLocale,
                'format' => 'text',
            ];
            if ($request->sourceLocale !== 'auto') {
                $payload['source'] = $request->sourceLocale;
            }

            $response = Http::connectTimeout(3)
                ->timeout(12)
                ->post('https://translation.googleapis.com/language/translate/v2?key=' . urlencode($apiKey), $payload);

            if ($response->status() === 429) {
                return $this->failBatch($texts, 'rate_limited', 'Google Translate вернул 429.');
            }

            if (!$response->successful()) {
                return $this->failBatch($texts, 'provider_error', 'Google Translate error: ' . $response->status());
            }

            $translations = $response->json('data.translations');

            return array_map(function (int $index) use ($translations): TranslationResult {
                $translated = is_array($translations) ? ($translations[$index]['translatedText'] ?? null) : null;

                if (!is_string($translated) || $translated === '') {
                    return TranslationResult::failure('empty_response', 'Google Translate вернул пустой перевод.', $this->key());
                }

                return TranslationResult::success(
                    html_entity_decode($translated, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    $this->key(),
                );
            }, array_keys(array_values($texts)));
        } catch (\Throwable $e) {
            return $this->failBatch($texts, 'timeout_or_network', $e->getMessage());
        }
    }

    /**
     * @param array<int, string> $texts
     *
     * @return array<int, TranslationResult>
     */
    private function failBatch(array $texts, string $code, string $message): array
    {
        return array_map(
            fn (): TranslationResult => TranslationResult::failure($code, $message, $this->key()),
            $texts,
        );
    }
}

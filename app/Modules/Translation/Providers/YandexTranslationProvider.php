<?php

namespace App\Modules\Translation\Providers;

use App\Modules\Translation\Contracts\TranslationProvider;
use App\Modules\Translation\DTOs\TranslationRequest;
use App\Modules\Translation\DTOs\TranslationResult;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Http;

class YandexTranslationProvider implements TranslationProvider
{
    public function __construct(private readonly SettingsService $settings)
    {
    }

    public function key(): string
    {
        return 'yandex';
    }

    public function isExternal(): bool
    {
        return true;
    }

    public function translate(TranslationRequest $request): TranslationResult
    {
        $apiKey = (string) ($this->settings->get('translation.yandex_api_key') ?? '');
        $folderId = (string) ($this->settings->get('translation.yandex_folder_id') ?? '');

        if ($apiKey === '' || $folderId === '') {
            return TranslationResult::failure('not_configured', 'Yandex Translate не настроен.', $this->key());
        }

        try {
            $payload = [
                'folderId' => $folderId,
                'texts' => [$request->text],
                'targetLanguageCode' => $request->targetLocale,
            ];

            if ($request->sourceLocale !== 'auto') {
                $payload['sourceLanguageCode'] = $request->sourceLocale;
            }

            $response = Http::timeout(12)
                ->withHeaders(['Authorization' => 'Api-Key ' . $apiKey])
                ->post('https://translate.api.cloud.yandex.net/translate/v2/translate', $payload);

            if ($response->status() === 429) {
                return TranslationResult::failure('rate_limited', 'Yandex Translate вернул 429.', $this->key());
            }

            if (!$response->successful()) {
                return TranslationResult::failure('provider_error', 'Yandex Translate error: ' . $response->status(), $this->key());
            }

            $translated = $response->json('translations.0.text');
            if (!is_string($translated) || $translated === '') {
                return TranslationResult::failure('empty_response', 'Yandex Translate вернул пустой перевод.', $this->key());
            }

            return TranslationResult::success($translated, $this->key());
        } catch (\Throwable $e) {
            return TranslationResult::failure('timeout_or_network', $e->getMessage(), $this->key());
        }
    }
}

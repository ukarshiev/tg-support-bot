<?php

namespace App\Modules\Translation\Providers;

use App\Modules\Translation\Contracts\BatchTranslationProvider;
use App\Modules\Translation\DTOs\TranslationRequest;
use App\Modules\Translation\DTOs\TranslationResult;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Http;

class YandexTranslationProvider implements BatchTranslationProvider
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
        return $this->translateBatch($request, [$request->text])[0]
            ?? TranslationResult::failure('empty_response', 'Yandex Translate вернул пустой перевод.', $this->key());
    }

    public function translateBatch(TranslationRequest $request, array $texts): array
    {
        $apiKey = (string) ($this->settings->get('translation.yandex_api_key') ?? '');
        $folderId = (string) ($this->settings->get('translation.yandex_folder_id') ?? '');

        if ($apiKey === '' || $folderId === '') {
            return $this->failBatch($texts, 'not_configured', 'Yandex Translate не настроен.');
        }

        try {
            $payload = [
                'folderId' => $folderId,
                'texts' => array_values($texts),
                'targetLanguageCode' => $request->targetLocale,
            ];

            if ($request->sourceLocale !== 'auto') {
                $payload['sourceLanguageCode'] = $request->sourceLocale;
            }

            $response = Http::connectTimeout(3)
                ->timeout(12)
                ->withHeaders(['Authorization' => 'Api-Key ' . $apiKey])
                ->post('https://translate.api.cloud.yandex.net/translate/v2/translate', $payload);

            if ($response->status() === 429) {
                return $this->failBatch($texts, 'rate_limited', 'Yandex Translate вернул 429.');
            }

            if (!$response->successful()) {
                return $this->failBatch($texts, 'provider_error', 'Yandex Translate error: ' . $response->status());
            }

            $translations = $response->json('translations');

            return array_map(function (int $index) use ($translations): TranslationResult {
                $translated = is_array($translations) ? ($translations[$index]['text'] ?? null) : null;

                if (!is_string($translated) || $translated === '') {
                    return TranslationResult::failure('empty_response', 'Yandex Translate вернул пустой перевод.', $this->key());
                }

                return TranslationResult::success($translated, $this->key());
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

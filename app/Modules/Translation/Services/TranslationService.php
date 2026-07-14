<?php

namespace App\Modules\Translation\Services;

use App\Models\TranslationCacheEntry;
use App\Models\TranslationUsageLog;
use App\Modules\Translation\DTOs\TranslationRequest;
use App\Modules\Translation\DTOs\TranslationResult;
use App\Modules\Translation\Support\PlaceholderProtector;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TranslationService
{
    private const CIRCUIT_PREFIX = 'translation_provider_circuit:';

    private const FAILURE_PREFIX = 'translation_provider_failures:';

    public function __construct(
        private readonly SettingsService $settings,
        private readonly TranslationProviderRegistry $providers,
        private readonly PlaceholderProtector $placeholders,
    ) {
    }

    public function translate(TranslationRequest $request): TranslationResult
    {
        $text = trim($request->text);
        if ($text === '') {
            return TranslationResult::failure('empty_text', 'Нет текста для перевода.');
        }

        if ($request->sourceLocale === $request->targetLocale) {
            return TranslationResult::success($text, 'same_locale', true);
        }

        $sourceHash = self::sourceHash($text);
        $cached = TranslationCacheEntry::where([
            'source_locale' => $request->sourceLocale,
            'target_locale' => $request->targetLocale,
            'source_hash' => $sourceHash,
            'status' => 'ready',
        ])->first();

        if ($cached !== null && is_string($cached->translated_text) && $cached->translated_text !== '') {
            return TranslationResult::success($cached->translated_text, (string) $cached->provider, true);
        }

        [$protectedText, $placeholderMap] = $this->placeholders->protect($text);

        foreach ($this->providerOrder() as $providerKey) {
            $provider = $this->providers->get($providerKey);
            if ($provider === null || $this->isCircuitOpen($providerKey)) {
                continue;
            }

            if ($provider->isExternal() && !$this->externalTranslationAllowed($request)) {
                $this->logUsage($providerKey, $request, false, 'external_disabled', 'Внешний перевод запрещён настройкой.');
                continue;
            }

            $result = $provider->translate(new TranslationRequest(
                sourceLocale: $request->sourceLocale,
                targetLocale: $request->targetLocale,
                text: $protectedText,
                purpose: $request->purpose,
                allowExternal: $request->allowExternal,
            ));

            $this->logUsage(
                $providerKey,
                $request,
                $result->success,
                $result->errorCode,
                $result->errorMessage,
            );

            if ($result->success && is_string($result->text)) {
                $this->resetFailures($providerKey);
                $translated = $this->placeholders->restore($result->text, $placeholderMap);

                TranslationCacheEntry::updateOrCreate(
                    [
                        'source_locale' => $request->sourceLocale,
                        'target_locale' => $request->targetLocale,
                        'source_hash' => $sourceHash,
                    ],
                    [
                        'source_text' => $text,
                        'translated_text' => $translated,
                        'provider' => $providerKey,
                        'status' => 'ready',
                        'meta' => ['purpose' => $request->purpose],
                    ]
                );

                return TranslationResult::success($translated, $providerKey);
            }

            if (in_array($result->errorCode, ['rate_limited', 'provider_error', 'timeout_or_network'], true)) {
                $this->registerFailure($providerKey);
            }
        }

        Log::channel('app')->warning('TranslationService: all providers failed', [
            'source_locale' => $request->sourceLocale,
            'target_locale' => $request->targetLocale,
            'purpose' => $request->purpose,
            'text_length' => mb_strlen($text),
        ]);

        return TranslationResult::failure('all_providers_failed', 'Не удалось выполнить перевод ни одним провайдером.');
    }

    public function translateWithProvider(string $providerKey, TranslationRequest $request): TranslationResult
    {
        $text = trim($request->text);
        if ($text === '') {
            return TranslationResult::failure('empty_text', 'Нет текста для перевода.', $providerKey);
        }

        if ($request->sourceLocale === $request->targetLocale) {
            return TranslationResult::success($text, 'same_locale', true);
        }

        $provider = $this->providers->get($providerKey);
        if ($provider === null) {
            return TranslationResult::failure('provider_not_found', 'Провайдер перевода не найден.', $providerKey);
        }

        if ($this->isCircuitOpen($providerKey)) {
            return TranslationResult::failure('provider_circuit_open', 'Провайдер временно отключён после ошибок.', $providerKey);
        }

        if ($provider->isExternal() && !$this->externalTranslationAllowed($request)) {
            $this->logUsage($providerKey, $request, false, 'external_disabled', 'Внешний перевод запрещён настройкой.');

            return TranslationResult::failure('external_disabled', 'Внешний перевод запрещён настройкой.', $providerKey);
        }

        [$protectedText, $placeholderMap] = $this->placeholders->protect($text);

        $result = $provider->translate(new TranslationRequest(
            sourceLocale: $request->sourceLocale,
            targetLocale: $request->targetLocale,
            text: $protectedText,
            purpose: $request->purpose,
            allowExternal: $request->allowExternal,
        ));

        $this->logUsage(
            $providerKey,
            $request,
            $result->success,
            $result->errorCode,
            $result->errorMessage,
        );

        if ($result->success && is_string($result->text)) {
            $this->resetFailures($providerKey);

            return TranslationResult::success($this->placeholders->restore($result->text, $placeholderMap), $providerKey);
        }

        if (in_array($result->errorCode, ['rate_limited', 'provider_error', 'timeout_or_network'], true)) {
            $this->registerFailure($providerKey);
        }

        return TranslationResult::failure(
            $result->errorCode ?? 'provider_error',
            $result->errorMessage ?? 'Провайдер не выполнил перевод.',
            $providerKey,
        );
    }

    public static function sourceHash(string $text): string
    {
        return hash('sha256', trim($text));
    }

    /**
     * @return array<int, string>
     */
    public function providerOrder(): array
    {
        $value = $this->settings->get('translation.provider_order');

        if (is_array($value) && $value !== []) {
            return array_values(array_filter($value, static fn ($item): bool => is_string($item) && $item !== ''));
        }

        return ['yandex', 'google', 'offline'];
    }

    private function externalTranslationAllowed(TranslationRequest $request): bool
    {
        if (!$request->allowExternal) {
            return false;
        }

        return (bool) $this->settings->get('translation.allow_external', false);
    }

    private function logUsage(
        string $provider,
        TranslationRequest $request,
        bool $success,
        ?string $errorCode = null,
        ?string $errorMessage = null,
    ): void {
        TranslationUsageLog::create([
            'provider' => $provider,
            'source_locale' => $request->sourceLocale,
            'target_locale' => $request->targetLocale,
            'characters' => mb_strlen($request->text),
            'success' => $success,
            'error_code' => $errorCode,
            'error_message' => $errorMessage === null ? null : mb_substr($errorMessage, 0, 512),
            'meta' => ['purpose' => $request->purpose],
        ]);
    }

    private function isCircuitOpen(string $provider): bool
    {
        return (bool) Cache::get(self::CIRCUIT_PREFIX . $provider, false);
    }

    private function registerFailure(string $provider): void
    {
        $key = self::FAILURE_PREFIX . $provider;
        $failures = (int) Cache::get($key, 0) + 1;
        Cache::put($key, $failures, now()->addMinutes(10));

        if ($failures >= 5) {
            Cache::put(self::CIRCUIT_PREFIX . $provider, true, now()->addMinutes(5));
            Cache::forget($key);
        }
    }

    private function resetFailures(string $provider): void
    {
        Cache::forget(self::FAILURE_PREFIX . $provider);
        Cache::forget(self::CIRCUIT_PREFIX . $provider);
    }
}

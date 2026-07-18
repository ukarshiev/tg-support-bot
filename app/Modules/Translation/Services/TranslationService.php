<?php

namespace App\Modules\Translation\Services;

use App\Models\TranslationCacheEntry;
use App\Models\TranslationUsageLog;
use App\Modules\Translation\Contracts\BatchTranslationProvider;
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

    private const BATCH_MAX_TEXTS = 25;

    private const BATCH_MAX_CHARS = 5000;

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

    /**
     * @param array<int, TranslationRequest> $requests
     *
     * @return array<int, TranslationResult>
     */
    public function translateMany(array $requests): array
    {
        $results = [];
        $pending = [];

        foreach ($requests as $index => $request) {
            $text = trim($request->text);

            if ($text === '') {
                $results[$index] = TranslationResult::failure('empty_text', 'Нет текста для перевода.');
                continue;
            }

            if ($request->sourceLocale === $request->targetLocale) {
                $results[$index] = TranslationResult::success($text, 'same_locale', true);
                continue;
            }

            $cached = $this->cachedResult($request, $text);
            if ($cached !== null) {
                $results[$index] = $cached;
                continue;
            }

            $pending[$index] = $request;
        }

        foreach ($this->groupRequests($pending) as $group) {
            foreach ($this->chunkRequests($group) as $chunk) {
                $results += $this->translateUncachedChunk($chunk);
            }
        }

        ksort($results);

        return $results;
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

    private function cachedResult(TranslationRequest $request, string $text): ?TranslationResult
    {
        $cached = TranslationCacheEntry::where([
            'source_locale' => $request->sourceLocale,
            'target_locale' => $request->targetLocale,
            'source_hash' => self::sourceHash($text),
            'status' => 'ready',
        ])->first();

        if ($cached !== null && is_string($cached->translated_text) && $cached->translated_text !== '') {
            return TranslationResult::success($cached->translated_text, (string) $cached->provider, true);
        }

        return null;
    }

    /**
     * @param array<int, TranslationRequest> $requests
     *
     * @return array<string, array<int, TranslationRequest>>
     */
    private function groupRequests(array $requests): array
    {
        $groups = [];

        foreach ($requests as $index => $request) {
            $key = implode('|', [
                $request->sourceLocale,
                $request->targetLocale,
                $request->purpose,
                $request->allowExternal ? 'external' : 'internal',
            ]);
            $groups[$key][$index] = $request;
        }

        return $groups;
    }

    /**
     * @param array<int, TranslationRequest> $requests
     *
     * @return array<int, array<int, TranslationRequest>>
     */
    private function chunkRequests(array $requests): array
    {
        $chunks = [];
        $current = [];
        $chars = 0;

        foreach ($requests as $index => $request) {
            $length = mb_strlen(trim($request->text));

            if ($current !== [] && (count($current) >= self::BATCH_MAX_TEXTS || $chars + $length > self::BATCH_MAX_CHARS)) {
                $chunks[] = $current;
                $current = [];
                $chars = 0;
            }

            $current[$index] = $request;
            $chars += $length;
        }

        if ($current !== []) {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * @param array<int, TranslationRequest> $requests
     *
     * @return array<int, TranslationResult>
     */
    private function translateUncachedChunk(array $requests): array
    {
        /** @var TranslationRequest $prototype */
        $prototype = reset($requests);
        $protected = [];
        $maps = [];
        $texts = [];

        foreach ($requests as $index => $request) {
            $text = trim($request->text);
            [$protectedText, $placeholderMap] = $this->placeholders->protect($text);
            $texts[$index] = $text;
            $protected[$index] = $protectedText;
            $maps[$index] = $placeholderMap;
        }

        foreach ($this->providerOrder() as $providerKey) {
            $provider = $this->providers->get($providerKey);
            if ($provider === null || $this->isCircuitOpen($providerKey)) {
                continue;
            }

            if ($provider->isExternal() && !$this->externalTranslationAllowed($prototype)) {
                foreach ($requests as $request) {
                    $this->logUsage($providerKey, $request, false, 'external_disabled', 'Внешний перевод запрещён настройкой.');
                }
                continue;
            }

            $providerResults = $provider instanceof BatchTranslationProvider
                ? $provider->translateBatch($prototype, array_values($protected))
                : $this->translateChunkOneByOne($providerKey, array_values($requests));

            $results = [];
            $successCount = 0;
            $indexes = array_keys($requests);

            foreach ($indexes as $position => $index) {
                $result = $providerResults[$position] ?? TranslationResult::failure('empty_response', 'Провайдер не вернул перевод.', $providerKey);
                $request = $requests[$index];

                $this->logUsage(
                    $providerKey,
                    $request,
                    $result->success,
                    $result->errorCode,
                    $result->errorMessage,
                );

                if ($result->success && is_string($result->text)) {
                    $successCount++;
                    $translated = $this->placeholders->restore($result->text, $maps[$index]);
                    $this->storeCache($request, $texts[$index], $translated, $providerKey);
                    $results[$index] = TranslationResult::success($translated, $providerKey);
                } else {
                    $results[$index] = $result;
                }
            }

            if ($successCount > 0) {
                $this->resetFailures($providerKey);

                return $results;
            }

            if ($this->hasTransientProviderError($providerResults)) {
                $this->registerFailure($providerKey);
            }
        }

        foreach ($requests as $index => $request) {
            $results[$index] = TranslationResult::failure('all_providers_failed', 'Не удалось выполнить перевод ни одним провайдером.');
        }

        return $results;
    }

    /**
     * @param array<int, TranslationRequest> $requests
     *
     * @return array<int, TranslationResult>
     */
    private function translateChunkOneByOne(string $providerKey, array $requests): array
    {
        return array_map(
            fn (TranslationRequest $request): TranslationResult => $this->translateWithProvider($providerKey, $request),
            $requests,
        );
    }

    /**
     * @param array<int, TranslationResult> $results
     */
    private function hasTransientProviderError(array $results): bool
    {
        foreach ($results as $result) {
            if (in_array($result->errorCode, ['rate_limited', 'provider_error', 'timeout_or_network'], true)) {
                return true;
            }
        }

        return false;
    }

    private function storeCache(TranslationRequest $request, string $sourceText, string $translated, string $providerKey): void
    {
        TranslationCacheEntry::updateOrCreate(
            [
                'source_locale' => $request->sourceLocale,
                'target_locale' => $request->targetLocale,
                'source_hash' => self::sourceHash($sourceText),
            ],
            [
                'source_text' => $sourceText,
                'translated_text' => $translated,
                'provider' => $providerKey,
                'status' => 'ready',
                'meta' => ['purpose' => $request->purpose],
            ]
        );
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

<?php

namespace App\Modules\Translation\Services;

use App\Services\Settings\SettingsService;

class SupportLanguageSettings
{
    public function __construct(private readonly SettingsService $settings)
    {
    }

    /**
     * @return array<string, array{code: string, name: string, native: string, enabled: bool, show_on_start: bool, sort_order: int}>
     */
    public function languages(): array
    {
        $configured = $this->settings->get('support.languages');
        if (is_array($configured) && $configured !== []) {
            return $this->normalize($this->mergeFallbackLanguages($configured));
        }

        return $this->fallbackLanguages();
    }

    /**
     * @return array<string, array{code: string, name: string, native: string, enabled: bool, show_on_start: bool, sort_order: int}>
     */
    public function enabledLanguages(): array
    {
        return array_filter($this->languages(), static fn (array $language): bool => $language['enabled']);
    }

    public function save(array $languages): void
    {
        $this->settings->set('support.languages', $this->normalize($languages));
    }

    public function find(?string $code): ?array
    {
        if ($code === null || $code === '') {
            return null;
        }

        return $this->languages()[$code] ?? null;
    }

    /**
     * @param array<mixed> $languages
     *
     * @return array<string, array{code: string, name: string, native: string, enabled: bool, show_on_start: bool, sort_order: int}>
     */
    private function normalize(array $languages): array
    {
        $normalized = [];

        foreach ($languages as $key => $language) {
            if (!is_array($language)) {
                continue;
            }

            $code = (string) ($language['code'] ?? $key);
            if ($code === '') {
                continue;
            }

            $normalized[$code] = [
                'code' => $code,
                'name' => (string) ($language['name'] ?? $code),
                'native' => (string) ($language['native'] ?? $code),
                'enabled' => (bool) ($language['enabled'] ?? true),
                'show_on_start' => (bool) ($language['show_on_start'] ?? true),
                'sort_order' => (int) ($language['sort_order'] ?? (count($normalized) + 1)),
            ];
        }

        uasort($normalized, static fn (array $a, array $b): int => $a['sort_order'] <=> $b['sort_order']);

        return $normalized;
    }

    /**
     * @param array<mixed> $configured
     *
     * @return array<mixed>
     */
    private function mergeFallbackLanguages(array $configured): array
    {
        $fallback = $this->fallbackLanguages();
        $configuredByCode = [];

        foreach ($configured as $key => $language) {
            if (!is_array($language)) {
                continue;
            }

            $code = (string) ($language['code'] ?? $key);
            if ($code !== '') {
                $configuredByCode[$code] = $language;
            }
        }

        $merged = [];
        foreach ($fallback as $code => $language) {
            $merged[$code] = array_merge($language, [
                'enabled' => false,
                'show_on_start' => false,
            ], $configuredByCode[$code] ?? [], [
                'code' => $code,
                'sort_order' => $language['sort_order'],
            ]);
            unset($configuredByCode[$code]);
        }

        $maxSortOrder = count($merged);
        foreach ($configuredByCode as $code => $language) {
            $language['sort_order'] = ++$maxSortOrder;
            $merged[$code] = $language;
        }

        return $merged;
    }

    /**
     * @return array<string, array{code: string, name: string, native: string, enabled: bool, show_on_start: bool, sort_order: int}>
     */
    private function fallbackLanguages(): array
    {
        $fallback = [];
        foreach ((array) config('support_languages.languages', []) as $code => $language) {
            $fallback[$code] = [
                'code' => (string) $code,
                'name' => (string) ($language['name'] ?? $code),
                'native' => (string) ($language['native'] ?? $code),
                'enabled' => true,
                'show_on_start' => true,
                'sort_order' => count($fallback) + 1,
            ];
        }

        return $fallback;
    }
}

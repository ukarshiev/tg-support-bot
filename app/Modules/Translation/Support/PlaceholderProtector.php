<?php

namespace App\Modules\Translation\Support;

class PlaceholderProtector
{
    private const MARKER_PATTERN = '/__TGSPH_[A-F0-9]{12}_\d{4}__/u';

    private const MAX_MARKERS = 10000;

    /**
     * Защитить переменные и ссылки от машинного перевода.
     *
     * Protected fragments заменяются непрозрачными ASCII-маркерами со служебной
     * сигнатурой. В отличие от XML/PUA этот формат стабильно проходит через
     * Yandex и Google: переводчик не видит ключ переменной, URL или mention.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    public function protect(string $text): array
    {
        $map = [];
        $index = 0;
        $markerPrefix = $this->markerPrefix($text);

        $protected = preg_replace_callback(
            '/(\{\{\s*[A-Za-z0-9_]+\s*\}\}|\{[A-Za-z0-9_.:-]+\}|https?:\/\/[^\s<>"\']+|@[A-Za-z0-9_]{3,})/u',
            static function (array $matches) use (&$map, &$index, $markerPrefix): string {
                if ($index >= self::MAX_MARKERS) {
                    throw new \OverflowException('Слишком много защищаемых фрагментов в одном тексте.');
                }

                $marker = sprintf('%s%04d__', $markerPrefix, $index++);
                $value = $matches[0];
                $map[$marker] = $value;

                return $marker;
            },
            $text
        );

        return [$protected ?? $text, $map];
    }

    /**
     * Вернуть защищённые фрагменты в переведённый текст.
     *
     * @param array<string, string> $map
     */
    public function restore(string $text, array $map): string
    {
        return strtr($text, $map);
    }

    /**
     * Восстановить фрагменты только если переводчик сохранил каждый marker
     * ровно один раз и не добавил неизвестные markers.
     *
     * @param array<string, string> $map
     */
    public function restoreSafely(string $text, array $map): ?string
    {
        if ($map === []) {
            return $text;
        }

        preg_match_all(self::MARKER_PATTERN, $text, $matches);
        $actualMarkers = array_count_values($matches[0]);

        foreach ($map as $marker => $_value) {
            if (($actualMarkers[$marker] ?? 0) !== 1) {
                return null;
            }
        }

        if (array_diff_key($actualMarkers, $map) !== []) {
            return null;
        }

        return $this->restore($text, $map);
    }

    private function markerPrefix(string $text): string
    {
        for ($nonce = 0; $nonce < 100; $nonce++) {
            $hash = strtoupper(substr(hash('sha256', $text . '|' . $nonce), 0, 12));
            $prefix = "__TGSPH_{$hash}_";

            if (!str_contains($text, $prefix)) {
                return $prefix;
            }
        }

        throw new \RuntimeException('Не удалось подобрать безопасную сигнатуру marker.');
    }

    /**
     * Проверить уже восстановленный или сохранённый перевод.
     *
     * Все защищаемые фрагменты должны совпасть с источником по значению и
     * количеству. Переводчик также не должен добавлять HTML/XML-разметку.
     */
    public function isValidTranslation(string $source, string $translated): bool
    {
        [, $map] = $this->protect($source);
        $expectedCounts = array_count_values(array_values($map));

        foreach ($expectedCounts as $value => $count) {
            if (substr_count($translated, $value) !== $count) {
                return false;
            }
        }

        if (substr_count($source, '<') !== substr_count($translated, '<')
            || substr_count($source, '>') !== substr_count($translated, '>')
        ) {
            return false;
        }

        preg_match_all('/\{\{\s*[^{}]+\s*\}\}/u', $source, $sourceMustache);
        preg_match_all('/\{\{\s*[^{}]+\s*\}\}/u', $translated, $translatedMustache);

        return $this->multiset($sourceMustache[0]) === $this->multiset($translatedMustache[0]);
    }

    /**
     * @param list<string> $values
     *
     * @return array<string, int>
     */
    private function multiset(array $values): array
    {
        $counts = array_count_values($values);
        ksort($counts);

        return $counts;
    }
}

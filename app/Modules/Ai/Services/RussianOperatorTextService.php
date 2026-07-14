<?php

namespace App\Modules\Ai\Services;

use App\Modules\Translation\DTOs\TranslationRequest;
use App\Modules\Translation\Services\TranslationService;
use RuntimeException;

class RussianOperatorTextService
{
    public function __construct(private readonly TranslationService $translation)
    {
    }

    public function normalize(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            throw new RuntimeException('AI provider returned empty operator text');
        }

        if ($this->isPredominantlyCyrillic($text)) {
            return $text;
        }

        $result = $this->translation->translate(new TranslationRequest(
            sourceLocale: 'auto',
            targetLocale: 'ru',
            text: $text,
            purpose: 'ai_operator_source',
        ));

        if (!$result->success || trim((string) $result->text) === '') {
            throw new RuntimeException('Не удалось подготовить русский текст для оператора: ' . ($result->errorCode ?? 'unknown'));
        }

        return trim($result->text);
    }

    private function isPredominantlyCyrillic(string $text): bool
    {
        preg_match_all('/\p{L}/u', $text, $allLetters);
        preg_match_all('/\p{Cyrillic}/u', $text, $cyrillicLetters);

        $letters = count($allLetters[0]);
        $cyrillic = count($cyrillicLetters[0]);

        return $letters > 0 && $cyrillic / $letters >= 0.5;
    }
}

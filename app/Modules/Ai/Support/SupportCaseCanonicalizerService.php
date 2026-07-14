<?php

declare(strict_types=1);

namespace App\Modules\Ai\Support;

use App\Models\AiSupportKnowledgeChunk;
use App\Modules\Translation\DTOs\TranslationRequest;
use App\Modules\Translation\DTOs\TranslationResult;
use App\Modules\Translation\Services\TranslationService;

class SupportCaseCanonicalizerService
{
    public function __construct(private readonly TranslationService $translation)
    {
    }

    public function canonicalize(AiSupportKnowledgeChunk $chunk, string $field, bool $force = false): TranslationResult
    {
        if (! in_array($field, ['question', 'answer'], true)) {
            return TranslationResult::failure('invalid_field', 'Неизвестное поле support-кейса.');
        }

        $manualColumn = $field . '_ru_manually_edited';
        $ruColumn = $field . '_ru';
        $sourceColumn = $field . '_original';

        if ((bool) $chunk->{$manualColumn} && ! $force) {
            return TranslationResult::failure('manual_protected', 'Ручная RU-правка не перезаписана.');
        }

        $source = trim((string) ($chunk->{$sourceColumn} ?: $chunk->{$field}));
        if ($source === '') {
            return TranslationResult::failure('empty_text', 'Нет текста для RU canonical.');
        }

        if ($this->looksRussian($source)) {
            $chunk->{$ruColumn} = $source;
            $chunk->{$field . '_translation_status'} = AiSupportKnowledgeChunk::TRANSLATION_TRANSLATED;
            $chunk->{$field . '_translation_provider'} = 'same_locale';
            $chunk->{$field . '_translation_error'} = null;
            $chunk->{$field . '_translated_at'} = now();
            $chunk->save();

            return TranslationResult::success($source, 'same_locale');
        }

        $result = $this->translation->translate(new TranslationRequest(
            sourceLocale: (string) ($chunk->source_locale ?: 'auto'),
            targetLocale: (string) ($chunk->target_locale ?: 'ru'),
            text: $source,
            purpose: 'support_case_ru_canonical',
        ));

        if (! $result->success || ! is_string($result->text) || trim($result->text) === '') {
            $chunk->{$field . '_translation_status'} = AiSupportKnowledgeChunk::TRANSLATION_FAILED;
            $chunk->{$field . '_translation_provider'} = $result->provider;
            $chunk->{$field . '_translation_error'} = mb_substr($result->errorMessage ?? 'Не удалось получить RU canonical.', 0, 2000);
            $chunk->save();

            return $result->success ? TranslationResult::failure('empty_result', 'Провайдер вернул пустой RU canonical.', $result->provider) : $result;
        }

        $chunk->{$ruColumn} = trim($result->text);
        $chunk->{$field . '_translation_status'} = AiSupportKnowledgeChunk::TRANSLATION_TRANSLATED;
        $chunk->{$field . '_translation_provider'} = $result->provider;
        $chunk->{$field . '_translation_error'} = null;
        $chunk->{$field . '_translated_at'} = now();
        $chunk->save();

        return $result;
    }

    private function looksRussian(string $text): bool
    {
        preg_match_all('/\p{Cyrillic}/u', $text, $cyrillic);
        preg_match_all('/\p{L}/u', $text, $letters);

        $lettersCount = count($letters[0]);
        if ($lettersCount === 0) {
            return true;
        }

        return count($cyrillic[0]) / $lettersCount >= 0.45;
    }
}

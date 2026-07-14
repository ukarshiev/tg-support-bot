<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Готовый RAG-фрагмент из пары «клиент → оператор».
 *
 * @property int         $id
 * @property string      $source_hash
 * @property string      $question
 * @property string      $answer
 * @property array|null  $keywords
 * @property array|null  $embedding
 * @property bool        $is_active
 * @property string      $status
 * @property string|null $moderation_reason
 * @property array|null  $moderation_risks
 * @property string|null $duplicate_group_key
 * @property array|null  $source_metadata
 * @property int         $priority
 */
class AiSupportKnowledgeChunk extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVIEW = 'review';

    public const STATUS_DISABLED = 'disabled';

    public const TRANSLATION_PENDING = 'pending';

    public const TRANSLATION_TRANSLATED = 'translated';

    public const TRANSLATION_MANUAL_EDITED = 'manual_edited';

    public const TRANSLATION_FAILED = 'failed';

    public const TRANSLATION_NEEDS_REVIEW = 'needs_review';

    /**
     * @return array<int, string>
     */
    public static function statuses(): array
    {
        return [self::STATUS_ACTIVE, self::STATUS_REVIEW, self::STATUS_DISABLED];
    }

    /**
     * @return array<int, string>
     */
    public static function translationStatuses(): array
    {
        return [
            self::TRANSLATION_PENDING,
            self::TRANSLATION_TRANSLATED,
            self::TRANSLATION_MANUAL_EDITED,
            self::TRANSLATION_FAILED,
            self::TRANSLATION_NEEDS_REVIEW,
        ];
    }

    public function translationStatusLabel(?string $status): string
    {
        return match ($status) {
            self::TRANSLATION_PENDING => 'Ждёт перевода',
            self::TRANSLATION_TRANSLATED => 'Переведено',
            self::TRANSLATION_MANUAL_EDITED => 'Ручная правка',
            self::TRANSLATION_FAILED => 'Ошибка',
            self::TRANSLATION_NEEDS_REVIEW => 'Нужно проверить',
            default => 'Неизвестно',
        };
    }

    public function canonicalQuestion(): string
    {
        return trim((string) ($this->question_ru ?: $this->question_original ?: $this->question));
    }

    public function canonicalAnswer(): string
    {
        return trim((string) ($this->answer_ru ?: $this->answer_original ?: $this->answer));
    }

    public function searchableRuQuestion(): string
    {
        return $this->isUsableRuCanonical($this->question_translation_status)
            ? trim((string) $this->question_ru)
            : '';
    }

    public function searchableRuAnswer(): string
    {
        return $this->isUsableRuCanonical($this->answer_translation_status)
            ? trim((string) $this->answer_ru)
            : '';
    }

    public function effectiveAiInstruction(): string
    {
        $instruction = trim((string) $this->ai_instruction);
        if ($instruction !== '') {
            return $instruction;
        }

        return 'Используй этот кейс только как пример похожего диалога. Не обещай цены, скидки, возвраты, продления, доступы или ручные действия, если это не подтверждено отдельной базой знаний.';
    }

    public function originalQuestion(): string
    {
        return trim((string) ($this->question_original ?: $this->question));
    }

    public function originalAnswer(): string
    {
        return trim((string) ($this->answer_original ?: $this->answer));
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_ACTIVE => 'Активен',
            self::STATUS_REVIEW => 'Нужно проверить',
            self::STATUS_DISABLED => 'Выключен',
            default => 'Неизвестно',
        };
    }

    private function isUsableRuCanonical(?string $status): bool
    {
        return in_array($status, [
            self::TRANSLATION_TRANSLATED,
            self::TRANSLATION_MANUAL_EDITED,
        ], true);
    }

    protected $table = 'ai_support_knowledge_chunks';

    protected $fillable = [
        'source_hash',
        'question',
        'answer',
        'question_original',
        'question_ru',
        'answer_original',
        'answer_ru',
        'ai_instruction',
        'source_locale',
        'target_locale',
        'question_translation_status',
        'answer_translation_status',
        'question_translation_provider',
        'answer_translation_provider',
        'question_translation_error',
        'answer_translation_error',
        'question_translated_at',
        'answer_translated_at',
        'question_ru_manually_edited',
        'answer_ru_manually_edited',
        'keywords',
        'embedding',
        'is_active',
        'status',
        'moderation_reason',
        'moderation_risks',
        'duplicate_group_key',
        'source_metadata',
        'priority',
        'first_message_at',
        'last_message_at',
    ];

    protected $casts = [
        'keywords' => 'array',
        'embedding' => 'array',
        'is_active' => 'boolean',
        'question_ru_manually_edited' => 'boolean',
        'answer_ru_manually_edited' => 'boolean',
        'moderation_risks' => 'array',
        'source_metadata' => 'array',
        'priority' => 'integer',
        'first_message_at' => 'datetime',
        'last_message_at' => 'datetime',
        'question_translated_at' => 'datetime',
        'answer_translated_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}

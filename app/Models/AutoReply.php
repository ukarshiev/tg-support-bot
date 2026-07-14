<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Auto-reply rule: a trigger phrase and the response sent when it matches.
 *
 * @property int                        $id
 * @property string                     $trigger
 * @property string                     $response
 * @property bool                       $enabled
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class AutoReply extends Model
{
    public const TYPE_REGULAR = 'regular';

    public const TYPE_WELCOME = 'welcome';

    public const TYPE_DIALOG_CLOSED = 'dialog_closed';

    public const TYPE_FEEDBACK_REQUEST = 'feedback_request';

    public const TYPE_FEEDBACK_THANK_YOU = 'feedback_thank_you';

    public const TYPE_BAN = 'ban';

    public const TRIGGER_WELCOME = '__system_welcome__';

    public const TRIGGER_DIALOG_CLOSED = '__system_dialog_closed__';

    public const TRIGGER_FEEDBACK_REQUEST = '__system_feedback_request__';

    public const TRIGGER_FEEDBACK_THANK_YOU = '__system_feedback_thank_you__';

    public const TRIGGER_BAN = '__system_ban__';

    /**
     * Mass-assignable attributes.
     *
     * @var list<string>
     */
    protected $fillable = [
        'type',
        'trigger',
        'response',
        'source_locale',
        'source_hash',
        'enabled',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    /**
     * Переводы автоответа по включённым языкам.
     */
    public function translations(): HasMany
    {
        return $this->hasMany(AutoReplyTranslation::class);
    }

    /**
     * Хэш русского источника для определения устаревших переводов.
     */
    public static function sourceHash(string $text): string
    {
        return hash('sha256', trim($text));
    }

    /**
     * Названия типов для UI.
     *
     * @return array<string, string>
     */
    public static function typeLabels(): array
    {
        return [
            self::TYPE_REGULAR => 'Обычный автоответ',
            self::TYPE_WELCOME => 'Приветственное сообщение',
            self::TYPE_DIALOG_CLOSED => 'Завершение диалога',
            self::TYPE_FEEDBACK_REQUEST => 'Запрос оценки поддержки',
            self::TYPE_FEEDBACK_THANK_YOU => 'Благодарность за оценку',
            self::TYPE_BAN => 'Бан',
        ];
    }

    /**
     * Стабильные триггеры системных автоответов.
     *
     * @return array<string, string>
     */
    public static function systemTriggers(): array
    {
        return [
            self::TYPE_WELCOME => self::TRIGGER_WELCOME,
            self::TYPE_DIALOG_CLOSED => self::TRIGGER_DIALOG_CLOSED,
            self::TYPE_FEEDBACK_REQUEST => self::TRIGGER_FEEDBACK_REQUEST,
            self::TYPE_FEEDBACK_THANK_YOU => self::TRIGGER_FEEDBACK_THANK_YOU,
            self::TYPE_BAN => self::TRIGGER_BAN,
        ];
    }

    public static function isSystemType(string $type): bool
    {
        return array_key_exists($type, self::systemTriggers());
    }
}

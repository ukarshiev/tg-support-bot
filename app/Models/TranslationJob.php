<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TranslationJob extends Model
{
    public const TYPE_AUTO_REPLY = 'auto_reply';

    public const TYPE_MESSAGE_HISTORY = 'message_history';

    public const TYPE_SUPPORT_CASE = 'support_case';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'job_type',
        'subject_type',
        'subject_id',
        'subject_label',
        'source_locale',
        'target_locale',
        'provider',
        'status',
        'attempts',
        'characters',
        'error_message',
        'queued_at',
        'started_at',
        'finished_at',
        'meta',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'characters' => 'integer',
        'queued_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'meta' => 'array',
    ];

    /** @return array<string, string> */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_QUEUED => 'В очереди',
            self::STATUS_RUNNING => 'Выполняется',
            self::STATUS_DONE => 'Готово',
            self::STATUS_FAILED => 'Ошибка',
            self::STATUS_SKIPPED => 'Пропущено',
        ];
    }

    /** @return array<string, string> */
    public static function typeLabels(): array
    {
        return [
            self::TYPE_AUTO_REPLY => 'Автоответ',
            self::TYPE_MESSAGE_HISTORY => 'История диалога',
            self::TYPE_SUPPORT_CASE => 'Support-кейс',
        ];
    }

    public function statusLabel(): string
    {
        return self::statusLabels()[$this->status] ?? $this->status;
    }

    public function typeLabel(): string
    {
        return self::typeLabels()[$this->job_type] ?? $this->job_type;
    }
}

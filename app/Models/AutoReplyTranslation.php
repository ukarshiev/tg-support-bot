<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutoReplyTranslation extends Model
{
    public const STATUS_EMPTY = 'empty';

    public const STATUS_READY = 'ready';

    public const STATUS_ERROR = 'error';

    public const STATUS_STALE = 'stale';

    public const SOURCE_AUTO = 'auto';

    public const SOURCE_MANUAL = 'manual';

    protected $fillable = [
        'auto_reply_id',
        'locale',
        'text',
        'status',
        'source',
        'provider',
        'source_hash',
        'translated_at',
    ];

    protected function casts(): array
    {
        return [
            'translated_at' => 'datetime',
        ];
    }

    public function autoReply(): BelongsTo
    {
        return $this->belongsTo(AutoReply::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageTranslation extends Model
{
    protected $fillable = [
        'message_id',
        'source_locale',
        'target_locale',
        'source_text',
        'translated_text',
        'direction',
        'status',
        'source',
        'provider',
        'source_hash',
        'error_message',
        'manual_edited_at',
        'translated_at',
    ];

    protected function casts(): array
    {
        return [
            'translated_at' => 'datetime',
            'manual_edited_at' => 'datetime',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TranslationCacheEntry extends Model
{
    protected $fillable = [
        'source_locale',
        'target_locale',
        'source_hash',
        'source_text',
        'translated_text',
        'provider',
        'status',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }
}

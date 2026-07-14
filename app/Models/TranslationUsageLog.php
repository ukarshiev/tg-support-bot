<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TranslationUsageLog extends Model
{
    protected $fillable = [
        'provider',
        'source_locale',
        'target_locale',
        'characters',
        'success',
        'error_code',
        'error_message',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'success' => 'boolean',
            'meta' => 'array',
        ];
    }
}

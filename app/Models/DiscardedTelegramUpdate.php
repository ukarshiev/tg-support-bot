<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DiscardedTelegramUpdate extends Model
{
    protected $fillable = [
        'update_id',
        'payload',
        'http_status',
        'attempts',
        'discarded_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'discarded_at' => 'datetime',
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AutoReplyVariable extends Model
{
    protected $fillable = [
        'key',
        'name',
        'value',
        'description',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    public static function token(string $key): string
    {
        return '{{' . $key . '}}';
    }

    public static function normalizeKey(string $key): string
    {
        $key = trim(mb_strtolower($key));
        $key = preg_replace('/[^a-z0-9_]+/', '_', $key) ?? $key;
        $key = trim($key, '_');

        return $key;
    }
}

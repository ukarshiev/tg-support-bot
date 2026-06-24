<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int         $id
 * @property string      $key        Dot-notation setting key (e.g. 'telegram.group_id')
 * @property string|null $value      Raw stored value; encrypted string when is_secret = true
 * @property string      $type       PHP type for coercion: 'string' | 'bool' | 'int' | 'json'
 * @property bool        $is_secret  When true, value is encrypted in the DB by SettingsService
 * @property string      $created_at
 * @property string      $updated_at
 *
 * NOTE: Encryption of the `value` column is handled by SettingsService, not by a model cast.
 * SettingsService uses Crypt::encrypt() before writing and Crypt::decrypt() after reading for
 * keys flagged is_secret=true. This avoids the fill()-order problem that arises when combining
 * a dynamic getCasts() override with Eloquent's attribute hydration sequence.
 */
class Setting extends Model
{
    use HasFactory;

    protected $table = 'settings';

    protected $fillable = [
        'key',
        'value',
        'type',
        'is_secret',
    ];

    protected $casts = [
        'is_secret' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}

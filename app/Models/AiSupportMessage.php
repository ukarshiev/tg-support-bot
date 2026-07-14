<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Нормализованное сообщение из support-архива.
 *
 * @property int         $id
 * @property string      $source_file
 * @property string      $telegram_message_id
 * @property string|null $message_datetime
 * @property string      $sender_name
 * @property string      $sender_role
 * @property string      $text
 * @property bool        $is_noise
 */
class AiSupportMessage extends Model
{
    use HasFactory;

    protected $table = 'ai_support_messages';

    protected $fillable = [
        'source_file',
        'telegram_message_id',
        'message_datetime',
        'sender_name',
        'sender_role',
        'text',
        'is_noise',
    ];

    protected $casts = [
        'message_datetime' => 'datetime',
        'is_noise' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}

<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * История импортов Telegram-архива support-диалогов.
 *
 * @property int        $id
 * @property string     $source_path
 * @property string     $mode
 * @property int        $messages_count
 * @property int        $chunks_count
 * @property array|null $metadata
 */
class AiSupportImportBatch extends Model
{
    use HasFactory;

    protected $table = 'ai_support_import_batches';

    protected $fillable = [
        'source_path',
        'mode',
        'messages_count',
        'chunks_count',
        'metadata',
    ];

    protected $casts = [
        'messages_count' => 'integer',
        'chunks_count' => 'integer',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}

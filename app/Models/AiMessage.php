<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int         $id
 * @property int         $bot_user_id
 * @property string|null $message_id
 * @property string      $status
 * @property string|null $text_ai
 * @property string|null $text_manager
 */
class AiMessage extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'ai_messages';

    protected $fillable = [
        'bot_user_id',
        'message_id',
        'status',
        'text_ai',
        'text_manager',
    ];

    /**
     * @return BelongsTo
     */
    public function botUser(): BelongsTo
    {
        return $this->belongsTo(BotUser::class);
    }

    /**
     * @param Builder $query
     *
     * @return Builder
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }
}

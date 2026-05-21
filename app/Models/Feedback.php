<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int         $id
 * @property int         $bot_user_id
 * @property int|null    $rating      1..5, null until user rates
 * @property string|null $comment     Optional text comment (nullable, reserved for future use)
 * @property string      $status      'awaiting_rating' | 'completed_no_comment'
 * @property string|null $closed_at   Timestamp when the conversation was closed
 * @property string      $created_at
 * @property string      $updated_at
 */
class Feedback extends Model
{
    use HasFactory;

    protected $table = 'feedbacks';

    protected $fillable = [
        'bot_user_id',
        'rating',
        'comment',
        'status',
        'closed_at',
    ];

    protected $casts = [
        'rating' => 'integer',
        'closed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * @return BelongsTo
     */
    public function botUser(): BelongsTo
    {
        return $this->belongsTo(BotUser::class);
    }
}

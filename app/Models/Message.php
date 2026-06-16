<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int                  $bot_user_id
 * @property string               $platform
 * @property string               $message_type
 * @property int                  $from_id
 * @property int                  $to_id
 * @property string|null          $text
 * @property int|null             $sender_user_id
 * @property string|null          $sender_name
 * @property ExternalMessage|null $externalMessage
 * @property BotUser|null         $botUser
 * @property User|null            $sender
 * @property-read \Illuminate\Database\Eloquent\Collection<int, MessageAttachment> $attachments
 */
class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'bot_user_id',
        'platform',
        'message_type',
        'from_id',
        'to_id',
        'text',
        'sender_user_id',
        'sender_name',
    ];

    /**
     * @return HasOne
     */
    public function externalMessage(): HasOne
    {
        return $this->hasOne(ExternalMessage::class);
    }

    /**
     * @return HasMany
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class);
    }

    /**
     * @return BelongsTo
     */
    public function botUser(): BelongsTo
    {
        return $this->belongsTo(BotUser::class);
    }

    /**
     * The admin-panel operator who sent this outgoing message.
     * Null for incoming messages, AI auto-replies, or Telegram-group replies.
     *
     * @return BelongsTo
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    /**
     * @param string $typeMessage
     * @param int    $from_id
     * @param string $source
     *
     * @return Message|null
     */
    public static function getMessageData(string $typeMessage, int $from_id, string $source): ?Message
    {
        try {
            $messageData = static::where([
                'message_type' => $typeMessage,
                'from_id' => $from_id,
                'platform' => $source,
            ])->first();

            if (empty($messageData)) {
                throw new \Exception('Message not found!');
            }

            return $messageData;
        } catch (\Throwable $th) {
            return null;
        }
    }
}

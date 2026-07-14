<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Schema;

/**
 * @property int                  $bot_user_id
 * @property string               $platform
 * @property string               $message_type
 * @property string               $message_kind
 * @property string|null          $delivery_status
 * @property string|null          $source_event_key
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

    private static bool $messageKindColumnConfirmed = false;

    private static bool $sourceEventKeyColumnConfirmed = false;

    private static bool $deliveryStatusColumnConfirmed = false;

    public const KIND_CHAT = 'chat';

    public const KIND_SYSTEM = 'system';

    public const KIND_LANGUAGE_SELECTOR = 'language_selector';

    public const KIND_BOT_MIRROR = 'bot_mirror';

    public const DELIVERY_PENDING = 'pending';

    public const DELIVERY_DELIVERED = 'delivered';

    public const DELIVERY_FAILED = 'failed';

    protected $fillable = [
        'bot_user_id',
        'platform',
        'message_type',
        'message_kind',
        'delivery_status',
        'source_event_key',
        'from_id',
        'to_id',
        'text',
        'sender_user_id',
        'sender_name',
    ];

    protected static function booted(): void
    {
        static::creating(function (Message $message): void {
            if (!array_key_exists('message_kind', $message->getAttributes()) && self::hasMessageKindColumn()) {
                $message->message_kind = self::KIND_CHAT;
            }
        });
    }

    private static function hasMessageKindColumn(): bool
    {
        if (self::$messageKindColumnConfirmed) {
            return true;
        }

        return self::$messageKindColumnConfirmed = Schema::hasColumn('messages', 'message_kind');
    }

    private static function hasSourceEventKeyColumn(): bool
    {
        if (self::$sourceEventKeyColumnConfirmed) {
            return true;
        }

        return self::$sourceEventKeyColumnConfirmed = Schema::hasColumn('messages', 'source_event_key');
    }

    public static function supportsStructuralKind(): bool
    {
        return self::hasMessageKindColumn();
    }

    public static function supportsDeliveryStatus(): bool
    {
        if (self::$deliveryStatusColumnConfirmed) {
            return true;
        }

        return self::$deliveryStatusColumnConfirmed = Schema::hasColumn('messages', 'delivery_status');
    }

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
     * Переводы сообщения для двухъязычного интерфейса.
     *
     * @return HasMany
     */
    public function translations(): HasMany
    {
        return $this->hasMany(MessageTranslation::class);
    }

    /**
     * @return BelongsTo
     */
    public function botUser(): BelongsTo
    {
        return $this->belongsTo(BotUser::class);
    }

    /**
     * Строит глобально уникальный ключ события с учётом платформы.
     */
    public static function sourceEventKey(
        string $platform,
        string|int $sourceScope,
        string|int $sourceEventId,
    ): string {
        return hash(
            'sha256',
            strtolower(trim($platform)) . "\0" . (string) $sourceScope . "\0" . (string) $sourceEventId,
        );
    }

    /**
     * Идемпотентно сохраняет одно внешнее событие. Старые сообщения без ключа
     * остаются нетронутыми, а повторная доставка нового события вернёт ту же запись.
     *
     * @param array<string, mixed> $attributes
     */
    public static function firstOrCreateForSourceEvent(
        string $platform,
        string|int $sourceEventId,
        array $attributes,
    ): self {
        $platform = strtolower(trim($platform));
        $botUserId = $attributes['bot_user_id'] ?? null;

        if (!is_int($botUserId) && !is_string($botUserId)) {
            throw new \InvalidArgumentException('bot_user_id is required for source event idempotency.');
        }

        if (!self::supportsStructuralKind()) {
            unset($attributes['message_kind']);
        }
        if (!self::supportsDeliveryStatus()) {
            unset($attributes['delivery_status']);
        }

        if (self::hasSourceEventKeyColumn()) {
            return self::firstOrCreate(
                ['source_event_key' => self::sourceEventKey($platform, $botUserId, $sourceEventId)],
                ['platform' => $platform, ...$attributes],
            );
        }

        // Во время rolling-deploy новый код может запуститься на несколько
        // секунд раньше миграции. Старые колонки всё равно позволяют безопасно
        // дедуплицировать событие в рамках одного диалога.
        return self::firstOrCreate(
            [
                'bot_user_id' => $botUserId,
                'platform' => $platform,
                'message_type' => $attributes['message_type'] ?? 'incoming',
                'from_id' => $attributes['from_id'] ?? $sourceEventId,
            ],
            $attributes,
        );
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

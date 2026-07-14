<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversationMessageCommitted implements ShouldBroadcastNow
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly int $conversationId,
        public readonly int $messageId,
        public readonly string $messageType,
        public readonly string $createdAt,
        public readonly string $traceId,
    ) {
    }

    public static function fromMessage(Message $message, string $traceId): self
    {
        return new self(
            conversationId: $message->bot_user_id,
            messageId: $message->id,
            messageType: $message->message_type,
            createdAt: $message->created_at?->toISOString() ?? now()->toISOString(),
            traceId: $traceId,
        );
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('support')];
    }

    public function broadcastAs(): string
    {
        return 'support.message.committed';
    }

    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'message_id' => $this->messageId,
            'message_type' => $this->messageType,
            'created_at' => $this->createdAt,
            'trace_id' => $this->traceId,
        ];
    }
}

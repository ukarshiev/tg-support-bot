<?php

namespace App\Observers;

use App\Events\ConversationMessageCommitted;
use App\Models\Message;
use App\Modules\Telegram\Support\TelegramPipelineTrace;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Log;

class MessageObserver implements ShouldHandleEventsAfterCommit
{
    public function created(Message $message): void
    {
        $this->broadcast($message);
    }

    public function updated(Message $message): void
    {
        if ($message->wasChanged(['to_id', 'text'])) {
            $this->broadcast($message);
        }
    }

    private function broadcast(Message $message): void
    {
        $traceId = $message->platform === 'telegram'
            ? 'telegram:message:' . $message->from_id
            : $message->platform . ':message:' . $message->from_id;

        try {
            event(ConversationMessageCommitted::fromMessage($message, $traceId));
            TelegramPipelineTrace::log('admin_event_broadcast', $traceId, [
                'message_id' => $message->id,
                'bot_user_id' => $message->bot_user_id,
            ]);
        } catch (\Throwable $e) {
            Log::channel('app')->warning('Realtime broadcast failed after message commit', [
                'source' => 'telegram_pipeline',
                'trace_id' => $traceId,
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

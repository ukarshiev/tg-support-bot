<?php

namespace App\Modules\Ai\Jobs;

use App\Models\AiMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/** Одноразовая SLA-проверка: незабранный черновик становится видимой тревогой в логах. */
class AlertStaleAiDraftJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(
        public readonly int $aiMessageId,
        public readonly int $slaMinutes = 15,
    ) {
        $this->onQueue('ai');
    }

    public function handle(): void
    {
        $draft = AiMessage::find($this->aiMessageId);
        if ($draft === null || $draft->status !== AiMessage::STATUS_PENDING) {
            return;
        }

        if ($draft->created_at !== null && $draft->created_at->gt(now()->subMinutes($this->slaMinutes))) {
            self::dispatch($draft->id, $this->slaMinutes)
                ->delay($draft->created_at->copy()->addMinutes($this->slaMinutes));

            return;
        }

        Log::channel('app')->critical('AI draft exceeded operator SLA', [
            'source' => 'ai_draft_sla_exceeded',
            'ai_message_id' => $draft->id,
            'bot_user_id' => $draft->bot_user_id,
            'sla_minutes' => $this->slaMinutes,
            'age_seconds' => $draft->created_at?->diffInSeconds(now()),
        ]);
    }
}

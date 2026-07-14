<?php

namespace Tests\Unit\Modules\Ai\Jobs;

use App\Models\AiMessage;
use App\Models\BotUser;
use App\Modules\Ai\Jobs\AlertStaleAiDraftJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AlertStaleAiDraftJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_logs_critical_alert_when_pending_draft_exceeds_sla(): void
    {
        $botUser = BotUser::create(['chat_id' => 801, 'platform' => 'telegram']);
        $draft = AiMessage::create([
            'bot_user_id' => $botUser->id,
            'text_ai' => 'Черновик',
            'status' => AiMessage::STATUS_PENDING,
        ]);
        $draft->forceFill(['created_at' => now()->subMinutes(20)])->saveQuietly();

        Log::shouldReceive('channel')->with('app')->once()->andReturnSelf();
        Log::shouldReceive('critical')->once()->withArgs(function (string $message, array $context) use ($draft): bool {
            return $message === 'AI draft exceeded operator SLA'
                && $context['source'] === 'ai_draft_sla_exceeded'
                && $context['ai_message_id'] === $draft->id;
        });

        (new AlertStaleAiDraftJob($draft->id, 15))->handle();
    }

    public function test_processed_draft_does_not_alert(): void
    {
        $botUser = BotUser::create(['chat_id' => 802, 'platform' => 'telegram']);
        $draft = AiMessage::create([
            'bot_user_id' => $botUser->id,
            'text_ai' => 'Ответ',
            'status' => AiMessage::STATUS_ACCEPTED,
        ]);

        Log::shouldReceive('channel')->never();

        (new AlertStaleAiDraftJob($draft->id, 15))->handle();
    }
}

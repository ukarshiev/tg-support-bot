<?php

namespace Tests\Unit\Infrastructure;

use App\Jobs\TranslateMessageHistoryJob;
use App\Modules\Ai\Jobs\SendAiReplyJob;
use App\Modules\Telegram\DTOs\TelegramUpdateDto;
use App\Modules\Telegram\DTOs\TGTextMessageDto;
use App\Modules\Telegram\Jobs\SendTelegramMessageJob;
use App\Modules\Telegram\Jobs\TelegramInteractiveLatencyProbeJob;
use Tests\Mocks\Tg\TelegramUpdateDtoMock;
use Tests\TestCase;

class RealtimePipelineConfigurationTest extends TestCase
{
    public function test_interactive_ai_and_translation_jobs_use_isolated_queues(): void
    {
        /** @var TelegramUpdateDto $update */
        $update = TelegramUpdateDtoMock::getDto();
        $interactive = new SendTelegramMessageJob(1, $update, TGTextMessageDto::from([
            'methodQuery' => 'sendMessage',
            'chat_id' => 1,
            'text' => 'test',
        ]), 'outgoing');
        $ai = new SendAiReplyJob(1, $update, 'test');
        $translation = new TranslateMessageHistoryJob(1, 1);

        $this->assertSame('telegram-interactive', $interactive->queue);
        $this->assertSame('ai', $ai->queue);
        $this->assertSame('translation', $translation->queue);
        $this->assertSame(5, config('queue.connections.redis.block_for'));
        $this->assertTrue(config('queue.connections.redis.after_commit'));
    }

    public function test_realtime_fallback_poll_is_thirty_seconds(): void
    {
        $view = file_get_contents(resource_path('views/livewire/chat/conversation-page.blade.php'));

        $this->assertStringContainsString('wire:poll.30s.keep-alive', $view);
        $this->assertStringNotContainsString('wire:poll.5s.keep-alive', $view);
    }

    public function test_latency_probe_uses_interactive_queue(): void
    {
        $job = new TelegramInteractiveLatencyProbeJob('run', 'probe', microtime(true));

        $this->assertSame('telegram-interactive', $job->queue);
    }
}

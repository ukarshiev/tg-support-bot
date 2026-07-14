<?php

namespace Tests\Unit\Modules\Ai\Actions;

use App\Models\AiMessage;
use App\Models\BotUser;
use App\Models\Message;
use App\Modules\Ai\Actions\AiAcceptMessage;
use App\Modules\Ai\Jobs\DeliverAiMessageJob;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AiAcceptMessageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        app(SettingsService::class)->set('telegram_ai.token', 'test-token');
    }

    public function test_accept_queues_delivery_but_does_not_mark_accepted_before_confirmation(): void
    {
        $botUser = BotUser::create([
            'chat_id' => 501,
            'platform' => 'telegram',
            'preferred_language_code' => 'ru',
        ]);
        $draft = AiMessage::create([
            'bot_user_id' => $botUser->id,
            'text_ai' => 'Ответ',
            'text_source' => 'Ответ',
            'translation_status' => 'ready',
            'status' => AiMessage::STATUS_PENDING,
        ]);

        (new AiAcceptMessage())->executeForDraft($draft);

        $this->assertSame('delivery_pending', $draft->fresh()->status);
        $this->assertSame(0, Message::count());
        Queue::assertPushed(DeliverAiMessageJob::class, function ($job) use ($draft): bool {
            return $job->aiMessageId === $draft->id
                && $job->deleteDraftAfterDelivery
                && $job->mirrorAfterDelivery;
        });
    }

    public function test_repeated_accept_does_not_queue_duplicate_delivery(): void
    {
        $botUser = BotUser::create(['chat_id' => 502, 'platform' => 'telegram']);
        $draft = AiMessage::create([
            'bot_user_id' => $botUser->id,
            'text_ai' => 'Ответ',
            'status' => 'delivery_pending',
        ]);

        (new AiAcceptMessage())->executeForDraft($draft);

        Queue::assertNotPushed(DeliverAiMessageJob::class);
    }

    public function test_foreign_draft_without_ready_translation_is_not_replaced_with_russian(): void
    {
        $botUser = BotUser::create([
            'chat_id' => 503,
            'platform' => 'telegram',
            'preferred_language_code' => 'de',
        ]);
        $draft = AiMessage::create([
            'bot_user_id' => $botUser->id,
            'text_ai' => 'Русский ответ',
            'text_source' => 'Русский ответ',
            'text_translated' => null,
            'translation_status' => 'error',
            'status' => AiMessage::STATUS_PENDING,
        ]);

        (new AiAcceptMessage())->executeForDraft($draft);

        $this->assertSame('delivery_pending', $draft->fresh()->status);
        Queue::assertPushed(DeliverAiMessageJob::class);
        $this->assertSame(0, Message::count());
    }
}

<?php

namespace Tests\Unit\Modules\Ai\Actions;

use App\Models\AiMessage;
use App\Models\BotUser;
use App\Models\Message;
use App\Modules\Ai\Actions\AcceptAiDraftReplyMessage;
use App\Modules\Ai\Jobs\DeliverAiMessageJob;
use App\Modules\Telegram\DTOs\TelegramUpdateDto;
use App\Modules\Telegram\Jobs\SendTelegramSimpleQueryJob;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AcceptAiDraftReplyMessageTest extends TestCase
{
    use RefreshDatabase;

    private BotUser $botUser;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        app(SettingsService::class)->set('telegram_ai.token', 'test-ai-token');
        app(SettingsService::class)->set('telegram.group_id', '-1001234567890');

        $this->botUser = BotUser::create([
            'chat_id' => '555001',
            'platform' => 'telegram',
            'topic_id' => 987,
        ]);
    }

    public function test_reply_to_pending_ai_draft_sends_edited_text_to_client_once(): void
    {
        $draft = AiMessage::create([
            'bot_user_id' => $this->botUser->id,
            'message_id' => 321,
            'text_ai' => 'Старый AI-текст',
            'text_manager' => '',
            'status' => AiMessage::STATUS_PENDING,
        ]);

        $handled = app(AcceptAiDraftReplyMessage::class)->handle(
            $this->replyUpdate('Отредактированный ответ', 321),
            $this->botUser,
        );

        $this->assertTrue($handled);
        $this->assertDatabaseHas('ai_messages', [
            'id' => $draft->id,
            'status' => 'delivery_pending',
            'text_ai' => 'Отредактированный ответ',
        ]);
        $this->assertSame(0, Message::count());
        Queue::assertPushed(DeliverAiMessageJob::class, function ($job) use ($draft): bool {
            return $job->aiMessageId === $draft->id && $job->deleteDraftAfterDelivery;
        });
    }

    public function test_empty_reply_to_ai_draft_shows_notice_and_does_not_send_to_client(): void
    {
        $draft = AiMessage::create([
            'bot_user_id' => $this->botUser->id,
            'message_id' => 322,
            'text_ai' => 'AI-текст',
            'text_manager' => '',
            'status' => AiMessage::STATUS_PENDING,
        ]);

        $handled = app(AcceptAiDraftReplyMessage::class)->handle(
            $this->replyUpdate('', 322),
            $this->botUser,
        );

        $this->assertTrue($handled);
        $this->assertDatabaseHas('ai_messages', [
            'id' => $draft->id,
            'status' => AiMessage::STATUS_PENDING,
        ]);
        $this->assertSame(0, Message::count());

        Queue::assertPushed(SendTelegramSimpleQueryJob::class, function (SendTelegramSimpleQueryJob $job) {
            return $job->queryParams->methodQuery === 'sendMessage'
                && $job->queryParams->message_thread_id === 987
                && $job->queryParams->text === 'Для изменения AI-подсказки отправьте текстовым reply.';
        });
    }

    public function test_reply_to_already_processed_draft_does_not_send_duplicate(): void
    {
        AiMessage::create([
            'bot_user_id' => $this->botUser->id,
            'message_id' => 323,
            'text_ai' => 'AI-текст',
            'text_manager' => '',
            'status' => AiMessage::STATUS_ACCEPTED,
        ]);

        $handled = app(AcceptAiDraftReplyMessage::class)->handle(
            $this->replyUpdate('Повторный текст', 323),
            $this->botUser,
        );

        $this->assertTrue($handled);
        $this->assertSame(0, Message::count());

        Queue::assertPushed(SendTelegramSimpleQueryJob::class, function (SendTelegramSimpleQueryJob $job) {
            return $job->queryParams->methodQuery === 'sendMessage'
                && $job->queryParams->text === 'AI-подсказка уже обработана.';
        });
    }

    public function test_regular_group_reply_is_not_handled_when_it_is_not_ai_draft_reply(): void
    {
        $handled = app(AcceptAiDraftReplyMessage::class)->handle(
            $this->replyUpdate('Обычный ответ', 999999),
            $this->botUser,
        );

        $this->assertFalse($handled);
        Queue::assertNothingPushed();
    }

    private function replyUpdate(string $text, int $replyToMessageId): TelegramUpdateDto
    {
        return new TelegramUpdateDto(
            updateId: 100,
            typeQuery: 'message',
            aiTechMessage: false,
            typeSource: 'supergroup',
            isBot: false,
            chatId: -1001234567890,
            replyToMessage: ['message_id' => $replyToMessageId],
            messageThreadId: $this->botUser->topic_id,
            messageId: 777,
            text: $text,
        );
    }
}

<?php

namespace Tests\Unit\Modules\Ai\Actions;

use App\Models\AiMessage;
use App\Models\BotUser;
use App\Models\DeliveryOperation;
use App\Models\Message;
use App\Modules\Ai\Actions\DeliverAiAnswerToUser;
use App\Modules\Ai\Jobs\DeliverAiMessageJob;
use App\Modules\Max\Api\MaxMethods;
use App\Modules\Max\DTOs\MaxAnswerDto;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DeliverAiAnswerToUserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(SettingsService::class)->set('telegram.token', 'main-token');
        app(SettingsService::class)->set('telegram.group_id', null);
    }

    public function test_persists_outgoing_message_only_after_platform_confirmation(): void
    {
        $botUser = BotUser::create(['chat_id' => 101, 'platform' => 'telegram']);
        $aiMessage = AiMessage::create([
            'bot_user_id' => $botUser->id,
            'text_ai' => 'Ответ',
            'text_source' => 'Ответ',
            'status' => 'delivery_pending',
        ]);
        Http::fake(['*' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 77, 'chat' => ['id' => 101]],
        ])]);

        $result = app(DeliverAiAnswerToUser::class)->execute($botUser, '<b>Hello</b>', null, $aiMessage);

        $this->assertTrue($result);
        $this->assertDatabaseHas('messages', [
            'bot_user_id' => $botUser->id,
            'message_type' => 'outgoing',
            'text' => 'Hello',
        ]);
        $this->assertDatabaseHas('delivery_operations', [
            'bot_user_id' => $botUser->id,
            'operation' => 'ai-answer',
            'status' => DeliveryOperation::STATUS_DELIVERED,
            'external_message_id' => 77,
        ]);
    }

    public function test_failed_api_attempt_is_observable_and_does_not_create_false_sent_message(): void
    {
        $botUser = BotUser::create(['chat_id' => 102, 'platform' => 'telegram']);
        $aiMessage = AiMessage::create([
            'bot_user_id' => $botUser->id,
            'text_ai' => 'Ответ',
            'status' => 'delivery_pending',
        ]);
        Http::fake(['*' => Http::response([
            'ok' => false,
            'error_code' => 500,
            'description' => 'Temporary failure',
        ], 500)]);

        $this->expectException(\RuntimeException::class);

        try {
            app(DeliverAiAnswerToUser::class)->execute($botUser, 'Hello', null, $aiMessage);
        } finally {
            $this->assertSame(0, Message::count());
            $this->assertDatabaseHas('delivery_operations', [
                'operation' => 'ai-answer',
                'status' => DeliveryOperation::STATUS_RETRYING,
            ]);
        }
    }

    public function test_failed_vk_attempt_does_not_create_false_sent_message(): void
    {
        app(SettingsService::class)->set('vk.token', 'vk-token');
        $botUser = BotUser::create(['chat_id' => 202, 'platform' => 'vk']);
        $aiMessage = AiMessage::create([
            'bot_user_id' => $botUser->id,
            'text_ai' => 'Ответ',
            'status' => 'delivery_pending',
        ]);
        Http::fake(['https://api.vk.com/*' => Http::response([
            'error' => ['error_msg' => 'Temporary failure'],
        ], 200)]);

        $this->expectException(\RuntimeException::class);

        try {
            app(DeliverAiAnswerToUser::class)->execute($botUser, 'Hello', null, $aiMessage);
        } finally {
            $this->assertSame(0, Message::count());
            $this->assertDatabaseHas('delivery_operations', [
                'operation' => 'ai-answer',
                'status' => DeliveryOperation::STATUS_RETRYING,
            ]);
        }
    }

    public function test_failed_max_attempt_does_not_create_false_sent_message(): void
    {
        $botUser = BotUser::create(['chat_id' => 203, 'platform' => 'max']);
        $aiMessage = AiMessage::create([
            'bot_user_id' => $botUser->id,
            'text_ai' => 'Ответ',
            'status' => 'delivery_pending',
        ]);
        $max = \Mockery::mock(MaxMethods::class);
        $max->shouldReceive('sendQuery')->once()->andReturn(new MaxAnswerDto(500, 'Temporary failure', null));
        app()->instance(MaxMethods::class, $max);

        $this->expectException(\RuntimeException::class);

        try {
            app(DeliverAiAnswerToUser::class)->execute($botUser, 'Hello', null, $aiMessage);
        } finally {
            $this->assertSame(0, Message::count());
            $this->assertDatabaseHas('delivery_operations', [
                'operation' => 'ai-answer',
                'status' => DeliveryOperation::STATUS_RETRYING,
            ]);
        }
    }

    public function test_foreign_client_gets_safe_english_not_russian_when_translation_is_invalid(): void
    {
        Queue::fake();
        $botUser = BotUser::create([
            'chat_id' => 103,
            'platform' => 'telegram',
            'preferred_language_code' => 'fr',
        ]);
        $aiMessage = AiMessage::create([
            'bot_user_id' => $botUser->id,
            'text_ai' => 'Русский ответ',
            'text_source' => 'Русский ответ',
            'text_translated' => '',
            'translation_status' => 'error',
            'status' => 'delivery_pending',
        ]);
        Http::fake(['*' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 78, 'chat' => ['id' => 103]],
        ])]);

        (new DeliverAiMessageJob($aiMessage->id, mirrorAfterDelivery: false))
            ->handle(app(DeliverAiAnswerToUser::class));

        $message = Message::firstOrFail();
        $this->assertSame('A support agent will reply shortly. We could not prepare a safe localized answer.', $message->text);
        $this->assertStringNotContainsString('Русский', (string) $message->text);
        $this->assertSame(AiMessage::STATUS_ACCEPTED, $aiMessage->fresh()->status);
    }

    public function test_terminal_job_failure_marks_ai_message_and_operation_failed(): void
    {
        $botUser = BotUser::create(['chat_id' => 104, 'platform' => 'telegram']);
        $aiMessage = AiMessage::create([
            'bot_user_id' => $botUser->id,
            'text_ai' => 'Ответ',
            'status' => 'delivery_pending',
        ]);
        DeliveryOperation::create([
            'operation_key' => hash('sha256', 'ai-delivery:' . $aiMessage->id),
            'bot_user_id' => $botUser->id,
            'trace_id' => 'test',
            'destination' => 'telegram-client',
            'operation' => 'ai-answer',
            'status' => DeliveryOperation::STATUS_RETRYING,
        ]);

        (new DeliverAiMessageJob($aiMessage->id))->failed(new \RuntimeException('network down'));

        $this->assertSame('delivery_failed', $aiMessage->fresh()->status);
        $this->assertDatabaseHas('delivery_operations', [
            'operation' => 'ai-answer',
            'status' => DeliveryOperation::STATUS_FAILED,
        ]);
    }
}

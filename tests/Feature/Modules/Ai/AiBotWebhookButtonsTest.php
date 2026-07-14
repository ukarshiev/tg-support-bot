<?php

namespace Tests\Feature\Modules\Ai;

use App\Models\AiMessage;
use App\Models\BotUser;
use App\Models\Message;
use App\Modules\Ai\Jobs\DeliverAiMessageJob;
use App\Modules\Telegram\Jobs\SendTelegramSimpleQueryJob;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AiBotWebhookButtonsTest extends TestCase
{
    use RefreshDatabase;

    private BotUser $botUser;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $settings = app(SettingsService::class);
        $settings->set('telegram_ai.token', 'ai-token');
        $settings->set('telegram_ai.secret', 'ai-secret');
        $settings->set('telegram.group_id', '-1003546470853');

        $this->botUser = BotUser::create([
            'chat_id' => '2099781047',
            'platform' => 'telegram',
            'topic_id' => 444,
        ]);
    }

    public function test_ai_bot_webhook_accept_button_sends_draft_to_client(): void
    {
        AiMessage::create([
            'bot_user_id' => $this->botUser->id,
            'message_id' => 555,
            'text_ai' => 'Готовый ответ AI',
            'text_manager' => '',
            'status' => AiMessage::STATUS_PENDING,
        ]);

        $response = $this->postJson('/api/ai-bot/webhook', $this->callbackPayload('ai_message_send_555'), [
            'X-Telegram-Bot-Api-Secret-Token' => 'ai-secret',
        ]);

        $response->assertNoContent();
        $this->assertDatabaseHas('ai_messages', [
            'bot_user_id' => $this->botUser->id,
            'message_id' => 555,
            'status' => 'delivery_pending',
        ]);
        $this->assertSame(0, Message::where('bot_user_id', $this->botUser->id)->count());
        Queue::assertPushed(DeliverAiMessageJob::class, fn (DeliverAiMessageJob $job): bool =>
            $job->deleteDraftAfterDelivery && $job->mirrorAfterDelivery);
    }

    public function test_ai_bot_webhook_edit_button_answers_callback_with_instruction(): void
    {
        AiMessage::create([
            'bot_user_id' => $this->botUser->id,
            'message_id' => 556,
            'text_ai' => 'Черновик AI',
            'text_manager' => '',
            'status' => AiMessage::STATUS_PENDING,
        ]);

        $response = $this->postJson('/api/ai-bot/webhook', $this->callbackPayload('ai_message_edit_556'), [
            'X-Telegram-Bot-Api-Secret-Token' => 'ai-secret',
        ]);

        $response->assertNoContent();
        $this->assertSame(0, Message::count());

        Queue::assertPushed(SendTelegramSimpleQueryJob::class, function (SendTelegramSimpleQueryJob $job) {
            return $job->queryParams->methodQuery === 'answerCallbackQuery'
                && (string) $job->queryParams->callback_query_id === 'callback-1'
                && str_contains((string) $job->queryParams->text, 'Ответьте reply на AI-подсказку');
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function callbackPayload(string $callbackData): array
    {
        return [
            'update_id' => 9001,
            'callback_query' => [
                'id' => 'callback-1',
                'from' => [
                    'id' => 123456,
                    'is_bot' => false,
                    'first_name' => 'Operator',
                ],
                'message' => [
                    'message_id' => 555,
                    'message_thread_id' => $this->botUser->topic_id,
                    'chat' => [
                        'id' => -1003546470853,
                        'type' => 'supergroup',
                    ],
                    'text' => 'AI-подсказка',
                ],
                'data' => $callbackData,
            ],
        ];
    }
}

<?php

namespace Tests\Feature\Modules\Telegram;

use App\Models\AiMessage;
use App\Models\BotUser;
use App\Models\Message;
use App\Modules\Ai\Jobs\SendAiDraftJob;
use App\Modules\Ai\Jobs\SendAiReplyJob;
use App\Modules\Telegram\Jobs\SendTelegramMessageJob;
use App\Modules\Telegram\Jobs\SendTelegramSimpleQueryJob;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AutoAiModeCommandWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_general_topic_auto_ai_command_is_handled_before_bot_user_lookup(): void
    {
        Queue::fake();

        $settings = app(SettingsService::class);
        $settings->set('telegram.token', 'test-token');
        $settings->set('telegram.secret_key', 'secret');
        $settings->set('telegram.group_id', '-1003546470853');
        $settings->set('ai.auto_reply', false);

        Http::fake([
            'https://api.telegram.org/bottest-token/getChatMember' => Http::response([
                'ok' => true,
                'result' => [
                    'status' => 'administrator',
                ],
            ]),
        ]);

        $response = $this->postJson('/api/telegram/bot', [
            'update_id' => 1,
                'message' => [
                'message_id' => 10,
                'text' => '/autoAi on',
                'from' => [
                    'id' => 12345,
                    'is_bot' => false,
                    'first_name' => 'Admin',
                ],
                'chat' => [
                    'id' => -1003546470853,
                    'type' => 'supergroup',
                ],
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => 'secret',
        ]);

        $response->assertOk();
        $this->assertTrue((bool) app(SettingsService::class)->get('ai.auto_reply'));
        Queue::assertPushed(SendTelegramSimpleQueryJob::class, function (SendTelegramSimpleQueryJob $job) {
            return $job->queryParams->message_thread_id === null
                && $job->queryParams->text === 'Auto AI: ON — AI отвечает клиентам сам.';
        });
    }

    public function test_private_message_dispatches_ai_reply_job_when_auto_ai_is_on(): void
    {
        Queue::fake();
        $this->preparePrivateAiSettings(autoReply: true);

        $this->privateMessageRequest('Нужна помощь')->assertOk();

        Queue::assertPushed(SendAiReplyJob::class);
        Queue::assertNotPushed(SendAiDraftJob::class);
    }

    public function test_private_message_dispatches_ai_draft_job_when_auto_ai_is_off(): void
    {
        Queue::fake();
        $this->preparePrivateAiSettings(autoReply: false);

        $this->privateMessageRequest('Нужна помощь')->assertOk();

        Queue::assertPushed(SendAiDraftJob::class);
        Queue::assertNotPushed(SendAiReplyJob::class);
    }

    public function test_private_message_reopens_closed_dialog_before_ai_gate(): void
    {
        Queue::fake();
        $this->preparePrivateAiSettings(autoReply: false, closed: true);

        $this->privateMessageRequest('Я снова пишу')->assertOk();

        $botUser = BotUser::where('chat_id', '222333')->where('platform', 'telegram')->firstOrFail();
        $this->assertFalse($botUser->isClosed());
        $this->assertNull($botUser->closed_at);
        Queue::assertPushed(SendAiDraftJob::class);
    }

    public function test_supergroup_reply_to_ai_draft_is_consumed_before_regular_delivery(): void
    {
        Queue::fake();

        $settings = app(SettingsService::class);
        $settings->set('telegram.secret_key', 'secret');
        $settings->set('telegram_ai.token', 'test-ai-token');
        $settings->set('telegram.group_id', '-1003546470853');

        $botUser = BotUser::create([
            'chat_id' => '777001',
            'platform' => 'telegram',
            'topic_id' => 444,
        ]);

        AiMessage::create([
            'bot_user_id' => $botUser->id,
            'message_id' => 333,
            'text_ai' => 'AI-черновик',
            'text_manager' => '',
            'status' => AiMessage::STATUS_PENDING,
        ]);

        $response = $this->postJson('/api/telegram/bot', [
            'update_id' => 3,
            'message' => [
                'message_id' => 334,
                'message_thread_id' => 444,
                'text' => 'Финальный текст оператора',
                'reply_to_message' => [
                    'message_id' => 333,
                ],
                'from' => [
                    'id' => 12345,
                    'is_bot' => false,
                    'first_name' => 'Admin',
                ],
                'chat' => [
                    'id' => -1003546470853,
                    'type' => 'supergroup',
                ],
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => 'secret',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('ai_messages', [
            'bot_user_id' => $botUser->id,
            'message_id' => 333,
            'status' => 'delivery_pending',
            'text_ai' => 'Финальный текст оператора',
        ]);
        $this->assertSame(0, Message::where('bot_user_id', $botUser->id)->where('message_type', 'outgoing')->count());

        Queue::assertNotPushed(SendTelegramMessageJob::class);
        Queue::assertPushed(\App\Modules\Ai\Jobs\DeliverAiMessageJob::class);
    }

    private function preparePrivateAiSettings(bool $autoReply, bool $closed = false): void
    {
        $settings = app(SettingsService::class);
        $settings->set('telegram.secret_key', 'secret');
        $settings->set('telegram.token', null);
        $settings->set('telegram.group_id', null);
        $settings->set('ai.enabled', true);
        $settings->set('ai.auto_reply', $autoReply);

        BotUser::create([
            'chat_id' => '222333',
            'platform' => 'telegram',
            'preferred_language_code' => 'ru',
            'preferred_language_name' => 'Русский',
            'is_closed' => $closed,
            'closed_at' => $closed ? now()->subDay() : null,
        ]);
    }

    private function privateMessageRequest(string $text): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/telegram/bot', [
            'update_id' => 2,
            'message' => [
                'message_id' => 20,
                'text' => $text,
                'from' => [
                    'id' => 222333,
                    'is_bot' => false,
                    'first_name' => 'Client',
                ],
                'chat' => [
                    'id' => 222333,
                    'type' => 'private',
                ],
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => 'secret',
        ]);
    }
}

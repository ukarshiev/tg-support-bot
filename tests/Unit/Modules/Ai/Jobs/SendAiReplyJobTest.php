<?php

namespace Tests\Unit\Modules\Ai\Jobs;

use App\Models\AiMessage;
use App\Models\BotUser;
use App\Models\Message;
use App\Modules\Ai\DTOs\AiResponseDto;
use App\Modules\Ai\Jobs\SendAiReplyJob;
use App\Modules\Ai\Services\AiAssistantService;
use App\Modules\Ai\Services\AiBotApi;
use App\Modules\Telegram\Jobs\SendTelegramSimpleQueryJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Mocks\Tg\TelegramUpdateDtoMock;
use Tests\TestCase;

class SendAiReplyJobTest extends TestCase
{
    use RefreshDatabase;

    private BotUser $botUser;

    protected function setUp(): void
    {
        parent::setUp();

        $settings = app(\App\Services\Settings\SettingsService::class);
        $settings->set('ai.default_provider', 'openai');

        $this->botUser = BotUser::getUserByChatId(time(), 'telegram');
        $this->botUser->topic_id = 77;
        $this->botUser->save();

        Queue::fake();
    }

    public function test_delivers_without_supergroup_post_when_ai_bot_not_configured(): void
    {
        // No AI bot token → supergroup posting skipped.
        app(\App\Services\Settings\SettingsService::class)->set('telegram_ai.token', null);
        Http::fake();

        $replyText = 'Auto reply without AI bot';

        $aiResponse = new AiResponseDto(
            response: $replyText,
            confidenceScore: 0.9,
            shouldEscalate: false,
            provider: 'openai',
            modelUsed: 'gpt-4',
            tokensUsed: 10,
            responseTime: 0.5,
        );

        $aiService = $this->createMock(AiAssistantService::class);
        $aiService->method('processMessage')->willReturn($aiResponse);

        $updateDto = TelegramUpdateDtoMock::getDto();
        $job = new SendAiReplyJob($this->botUser->id, $updateDto, 'user question');

        $job->handle(new AiBotApi(), $aiService);

        // AiMessage persisted with accepted status, no Telegram message_id.
        $this->assertDatabaseHas('ai_messages', [
            'bot_user_id' => $this->botUser->id,
            'message_id' => null,
            'status' => AiMessage::STATUS_ACCEPTED,
        ]);

        // No HTTP calls to Telegram (AI bot not configured).
        Http::assertNothingSent();

        // Outgoing message row persisted regardless of platform send outcome.
        $this->assertDatabaseHas('messages', [
            'bot_user_id' => $this->botUser->id,
            'platform' => 'telegram',
            'message_type' => 'outgoing',
            'text' => $replyText,
        ]);

        // Simple job dispatched for user delivery (not the saving full job).
        Queue::assertPushed(SendTelegramSimpleQueryJob::class);
    }

    public function test_posts_to_supergroup_when_ai_bot_configured(): void
    {
        $aiToken = 'ai_reply_token_999';
        $groupId = -100111222333;

        $settings = app(\App\Services\Settings\SettingsService::class);
        $settings->set('telegram_ai.token', $aiToken);
        $settings->set('telegram.token', 'main_bot_token');
        $settings->set('telegram.secret_key', 'secret');
        $settings->set('telegram.group_id', (string) $groupId);

        $replyText = 'Supergroup auto reply';

        Http::fake([
            'https://api.telegram.org/bot' . $aiToken . '/sendMessage' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 555,
                    'chat' => ['id' => $groupId, 'type' => 'supergroup'],
                    'date' => time(),
                    'text' => $replyText,
                ],
            ], 200),
            // Fallback for any other calls.
            '*' => Http::response(['ok' => true, 'result' => ['message_id' => 556]], 200),
        ]);

        $aiResponse = new AiResponseDto(
            response: $replyText,
            confidenceScore: 0.9,
            shouldEscalate: false,
            provider: 'openai',
            modelUsed: 'gpt-4',
            tokensUsed: 10,
            responseTime: 0.5,
        );

        $aiService = $this->createMock(AiAssistantService::class);
        $aiService->method('processMessage')->willReturn($aiResponse);

        $updateDto = TelegramUpdateDtoMock::getDto();
        $job = new SendAiReplyJob($this->botUser->id, $updateDto, 'user question');

        $job->handle(new AiBotApi(), $aiService);

        $this->assertDatabaseHas('ai_messages', [
            'bot_user_id' => $this->botUser->id,
            'message_id' => 555,
            'status' => AiMessage::STATUS_ACCEPTED,
        ]);

        // Outgoing message persisted for admin thread visibility.
        $this->assertDatabaseHas('messages', [
            'bot_user_id' => $this->botUser->id,
            'platform' => 'telegram',
            'message_type' => 'outgoing',
            'text' => $replyText,
        ]);

        // Exactly one outgoing row (no duplicates from save+simple).
        $this->assertEquals(
            1,
            Message::where('bot_user_id', $this->botUser->id)->where('message_type', 'outgoing')->count()
        );

        // Simple job dispatched for user delivery.
        Queue::assertPushed(SendTelegramSimpleQueryJob::class);
    }

    public function test_auto_reply_persists_message_even_when_no_supergroup(): void
    {
        app(\App\Services\Settings\SettingsService::class)->set('telegram_ai.token', null);

        $replyText = 'Auto reply text';

        $aiResponse = new AiResponseDto(
            response: $replyText,
            confidenceScore: 0.9,
            shouldEscalate: false,
            provider: 'openai',
            modelUsed: 'gpt-4',
            tokensUsed: 10,
            responseTime: 0.5,
        );

        $aiService = $this->createMock(AiAssistantService::class);
        $aiService->method('processMessage')->willReturn($aiResponse);

        $job = new SendAiReplyJob($this->botUser->id, null, 'user question');
        $job->handle(new AiBotApi(), $aiService);

        // Outgoing row must exist before any send job runs (Queue is faked → job
        // never executes, simulating a platform failure scenario).
        $this->assertDatabaseHas('messages', [
            'bot_user_id' => $this->botUser->id,
            'message_type' => 'outgoing',
        ]);
    }
}

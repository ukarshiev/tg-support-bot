<?php

namespace Tests\Unit\Modules\Ai\Jobs;

use App\Models\AiMessage;
use App\Models\BotUser;
use App\Modules\Ai\DTOs\AiResponseDto;
use App\Modules\Ai\Jobs\SendAiDraftJob;
use App\Modules\Ai\Services\AiAssistantService;
use App\Modules\Ai\Services\AiBotApi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Mocks\Tg\TelegramUpdateDtoMock;
use Tests\TestCase;

class SendAiDraftJobTest extends TestCase
{
    use RefreshDatabase;

    private BotUser $botUser;

    private string $aiToken;

    private int $groupId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aiToken = 'ai_test_token_456';
        $this->groupId = -100987654321;

        $settings = app(\App\Services\Settings\SettingsService::class);
        $settings->set('telegram_ai.token', $this->aiToken);
        $settings->set('telegram.token', 'main_bot_token');
        $settings->set('telegram.secret_key', 'secret');
        $settings->set('telegram.group_id', (string) $this->groupId);
        $settings->set('ai.default_provider', 'openai');

        $this->botUser = BotUser::getUserByChatId(time(), 'telegram');
        $this->botUser->topic_id = 77;
        $this->botUser->save();

        Queue::fake();
    }

    public function test_posts_draft_to_supergroup_when_ai_bot_configured(): void
    {
        $aiResponseText = 'Black tea is fine for the demo';
        $aiResponse = new AiResponseDto(
            response: $aiResponseText,
            confidenceScore: 0.9,
            shouldEscalate: false,
            provider: 'openai',
            modelUsed: 'gpt-4',
            tokensUsed: 10,
            responseTime: 0.5,
        );

        $aiService = $this->createMock(AiAssistantService::class);
        $aiService->method('processMessage')->willReturn($aiResponse);

        Http::fake([
            'https://api.telegram.org/bot' . $this->aiToken . '/sendMessage' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 321,
                    'chat' => ['id' => $this->groupId, 'type' => 'supergroup'],
                    'date' => time(),
                    'text' => $aiResponseText,
                ],
            ], 200),
            'https://api.telegram.org/bot' . $this->aiToken . '/editMessageReplyMarkup' => Http::response([
                'ok' => true,
                'result' => true,
            ], 200),
        ]);

        $updateDto = TelegramUpdateDtoMock::getDto();
        $job = new SendAiDraftJob($this->botUser->id, $updateDto, 'user question');

        $job->handle(new AiBotApi(), $aiService);

        $this->assertDatabaseHas('ai_messages', [
            'bot_user_id' => $this->botUser->id,
            'message_id' => 321,
            'text_ai' => $aiResponseText,
            'status' => 'pending',
        ]);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), $this->aiToken . '/sendMessage');
        });
    }

    public function test_creates_pending_ai_message_without_telegram_when_ai_bot_not_configured(): void
    {
        // Remove AI bot token — AI bot not configured.
        app(\App\Services\Settings\SettingsService::class)->set('telegram_ai.token', null);
        Http::fake();

        $aiResponseText = 'AI response in admin panel only';
        $aiResponse = new AiResponseDto(
            response: $aiResponseText,
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
        $job = new SendAiDraftJob($this->botUser->id, $updateDto, 'user question');

        $job->handle(new AiBotApi(), $aiService);

        $this->assertDatabaseHas('ai_messages', [
            'bot_user_id' => $this->botUser->id,
            'message_id' => null,
            'text_ai' => $aiResponseText,
            'status' => 'pending',
        ]);

        // Must NOT have made any HTTP calls to Telegram.
        Http::assertNothingSent();
    }

    public function test_does_not_throw_when_ai_returns_null(): void
    {
        $aiService = $this->createMock(AiAssistantService::class);
        $aiService->method('processMessage')->willReturn(null);

        $updateDto = TelegramUpdateDtoMock::getDto();
        $job = new SendAiDraftJob($this->botUser->id, $updateDto, 'user question');

        $job->handle(new AiBotApi(), $aiService);

        $this->assertSame(0, AiMessage::count());
    }

    public function test_releases_when_topic_id_missing_and_ai_bot_configured(): void
    {
        // Remove topic_id so the job should release.
        $this->botUser->topic_id = null;
        $this->botUser->save();

        $aiService = $this->createMock(AiAssistantService::class);
        $aiService->expects($this->never())->method('processMessage');

        Http::fake();

        $updateDto = TelegramUpdateDtoMock::getDto();
        $job = new SendAiDraftJob($this->botUser->id, $updateDto, 'user question');

        $job->handle(new AiBotApi(), $aiService);

        // No AI message should be created — job was released.
        $this->assertSame(0, AiMessage::count());
    }
}

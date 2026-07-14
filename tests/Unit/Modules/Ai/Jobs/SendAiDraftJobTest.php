<?php

namespace Tests\Unit\Modules\Ai\Jobs;

use App\Models\AiMessage;
use App\Models\BotUser;
use App\Modules\Ai\DTOs\AiRequestDto;
use App\Modules\Ai\DTOs\AiResponseDto;
use App\Modules\Ai\Jobs\SendAiDraftJob;
use App\Modules\Ai\Services\AiAssistantService;
use App\Modules\Ai\Services\AiBotApi;
use App\Modules\Ai\Services\RussianOperatorTextService;
use App\Modules\Telegram\Jobs\TopicCreateJob;
use App\Modules\Translation\DTOs\TranslationRequest;
use App\Modules\Translation\DTOs\TranslationResult;
use App\Modules\Translation\Services\TranslationService;
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
        $aiResponseText = 'Чёрный чай подходит для демонстрации';
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
            return str_contains($request->url(), $this->aiToken . '/sendMessage')
                && str_contains((string) ($request->data()['text'] ?? ''), 'ИИ-черновик');
        });
    }

    public function test_operator_block_stays_russian_when_client_language_is_not_russian(): void
    {
        $this->botUser->preferred_language_code = 'pl';
        $this->botUser->preferred_language_name = 'Polski';
        $this->botUser->save();

        $providerText = 'Bonjour! Comment puis-je vous aider?';
        $aiResponseText = 'Привет! Чем могу помочь?';
        $translatedText = 'Cześć! W czym mogę pomóc?';
        $aiResponse = new AiResponseDto(
            response: $providerText,
            confidenceScore: 0.9,
            shouldEscalate: false,
            provider: 'openai',
            modelUsed: 'gpt-4',
            tokensUsed: 10,
            responseTime: 0.5,
        );

        $aiService = $this->createMock(AiAssistantService::class);
        $aiService->expects($this->once())
            ->method('processMessage')
            ->with($this->callback(function (AiRequestDto $request): bool {
                return $request->preferredLanguageCode === 'ru'
                    && $request->preferredLanguageName === 'Русский';
            }))
            ->willReturn($aiResponse);

        $translation = $this->createMock(TranslationService::class);
        $translation->expects($this->once())
            ->method('translate')
            ->with($this->callback(function (TranslationRequest $request) use ($aiResponseText): bool {
                return $request->sourceLocale === 'ru'
                    && $request->targetLocale === 'pl'
                    && $request->text === $aiResponseText
                    && $request->purpose === 'ai_draft';
            }))
            ->willReturn(TranslationResult::success($translatedText, 'fake'));
        $this->app->instance(TranslationService::class, $translation);

        $normalizer = $this->createMock(RussianOperatorTextService::class);
        $normalizer->expects($this->once())
            ->method('normalize')
            ->with($providerText)
            ->willReturn($aiResponseText);
        $this->app->instance(RussianOperatorTextService::class, $normalizer);

        Http::fake([
            'https://api.telegram.org/bot' . $this->aiToken . '/sendMessage' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 654,
                    'chat' => ['id' => $this->groupId, 'type' => 'supergroup'],
                    'date' => time(),
                    'text' => 'draft',
                ],
            ], 200),
            'https://api.telegram.org/bot' . $this->aiToken . '/editMessageReplyMarkup' => Http::response([
                'ok' => true,
                'result' => true,
            ], 200),
        ]);

        $job = new SendAiDraftJob($this->botUser->id, TelegramUpdateDtoMock::getDto(), 'Cześć');

        $job->handle(new AiBotApi(), $aiService);

        Http::assertSent(function ($request) use ($aiResponseText, $translatedText): bool {
            $text = (string) ($request->data()['text'] ?? '');

            return str_contains($request->url(), $this->aiToken . '/sendMessage')
                && str_contains($text, '<b>🇷🇺 Для оператора:</b>' . "\n" . $aiResponseText)
                && str_contains($text, '<b>🌐 Клиенту на PL:</b>' . "\n" . $translatedText);
        });
    }

    public function test_creates_pending_ai_message_without_telegram_when_ai_bot_not_configured(): void
    {
        // Remove AI bot token — AI bot not configured.
        app(\App\Services\Settings\SettingsService::class)->set('telegram_ai.token', null);
        Http::fake();

        $aiResponseText = 'Ответ ИИ только для панели администратора';
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

    public function test_rethrows_when_ai_returns_null_so_queue_can_retry(): void
    {
        $aiService = $this->createMock(AiAssistantService::class);
        $aiService->method('processMessage')->willReturn(null);

        $updateDto = TelegramUpdateDtoMock::getDto();
        $job = new SendAiDraftJob($this->botUser->id, $updateDto, 'user question');

        $this->expectException(\RuntimeException::class);

        try {
            $job->handle(new AiBotApi(), $aiService);
        } finally {
            $this->assertSame(0, AiMessage::count());
        }
    }

    public function test_missing_topic_does_not_lose_draft_and_queues_topic_creation(): void
    {
        $this->botUser->topic_id = null;
        $this->botUser->save();

        $aiResponseText = 'Черновик доступен оператору';
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
        $aiService->expects($this->once())->method('processMessage')->willReturn($aiResponse);

        Http::fake();

        $updateDto = TelegramUpdateDtoMock::getDto();
        $job = new SendAiDraftJob($this->botUser->id, $updateDto, 'user question');

        $job->handle(new AiBotApi(), $aiService);

        $this->assertDatabaseHas('ai_messages', [
            'bot_user_id' => $this->botUser->id,
            'message_id' => null,
            'text_ai' => $aiResponseText,
            'status' => AiMessage::STATUS_PENDING,
        ]);
        Queue::assertPushed(TopicCreateJob::class, fn (TopicCreateJob $job): bool => true);
        Http::assertNothingSent();
    }
}

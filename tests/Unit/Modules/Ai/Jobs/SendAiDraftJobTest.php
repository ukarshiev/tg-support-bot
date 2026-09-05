<?php

namespace Tests\Unit\Modules\Ai\Jobs;

use App\Models\AiMessage;
use App\Models\BotUser;
use App\Models\Message;
use App\Modules\Ai\Actions\AiAcceptMessage;
use App\Modules\Ai\DTOs\AiRequestDto;
use App\Modules\Ai\DTOs\AiResponseDto;
use App\Modules\Ai\Jobs\AlertStaleAiDraftJob;
use App\Modules\Ai\Jobs\SendAiDraftJob;
use App\Modules\Ai\Jobs\SendPendingAiDraftToTelegramJob;
use App\Modules\Ai\Services\AiAssistantService;
use App\Modules\Ai\Services\AiBotApi;
use App\Modules\Ai\Services\RussianOperatorTextService;
use App\Modules\Telegram\Jobs\TopicCreateJob;
use App\Modules\Translation\DTOs\TranslationRequest;
use App\Modules\Translation\DTOs\TranslationResult;
use App\Modules\Translation\Services\TranslationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
        Cache::forget("telegram:topic-create-requested:bot-user:{$this->botUser->id}");

        Queue::fake();
    }

    public function test_persists_draft_before_queueing_telegram_delivery(): void
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

        Http::fake();

        $updateDto = TelegramUpdateDtoMock::getDto();
        $job = new SendAiDraftJob($this->botUser->id, $updateDto, 'user question');

        $job->handle($aiService);

        $this->assertDatabaseHas('ai_messages', [
            'bot_user_id' => $this->botUser->id,
            'message_id' => null,
            'text_ai' => $aiResponseText,
            'status' => 'pending',
        ]);

        Queue::assertPushed(SendPendingAiDraftToTelegramJob::class, function (SendPendingAiDraftToTelegramJob $job): bool {
            return $job->aiMessageId === AiMessage::query()->sole()->id;
        });
        Http::assertNothingSent();
    }

    public function test_retry_does_not_create_or_deliver_duplicate_draft(): void
    {
        $aiResponseText = 'Один и тот же ответ ИИ';
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
        $aiService->expects($this->exactly(2))->method('processMessage')->willReturn($aiResponse);
        $job = new SendAiDraftJob($this->botUser->id, TelegramUpdateDtoMock::getDto(), 'user question');

        $job->handle($aiService);
        $job->handle($aiService);

        $this->assertSame(1, AiMessage::query()->count());
        $this->assertDatabaseHas('ai_messages', [
            'bot_user_id' => $this->botUser->id,
            'source_hash' => hash('sha256', $aiResponseText),
        ]);
        Queue::assertPushed(SendPendingAiDraftToTelegramJob::class, 1);
        Queue::assertPushed(AlertStaleAiDraftJob::class, 1);
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
                'result' => [
                    'message_id' => 654,
                    'chat' => ['id' => $this->groupId, 'type' => 'supergroup'],
                    'date' => time(),
                ],
            ], 200),
        ]);

        $job = new SendAiDraftJob($this->botUser->id, TelegramUpdateDtoMock::getDto(), 'Cześć');

        $job->handle($aiService);
        (new SendPendingAiDraftToTelegramJob(AiMessage::query()->sole()->id))->handle(new AiBotApi());

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

        $job->handle($aiService);

        $this->assertDatabaseHas('ai_messages', [
            'bot_user_id' => $this->botUser->id,
            'message_id' => null,
            'text_ai' => $aiResponseText,
            'status' => 'pending',
        ]);

        // Must NOT have made any HTTP calls to Telegram.
        Http::assertNothingSent();
    }

    public function test_empty_provider_response_is_logged_and_does_not_create_draft(): void
    {
        $aiService = $this->createMock(AiAssistantService::class);
        $aiService->method('processMessage')->willReturn(null);

        $updateDto = TelegramUpdateDtoMock::getDto();
        $job = new SendAiDraftJob($this->botUser->id, $updateDto, 'user question');

        $this->expectExceptionMessage('AI provider returned an empty response; draft was not created');

        try {
            $job->handle($aiService);
        } finally {
            $this->assertSame(0, AiMessage::count());
        }
    }

    public function test_missing_topic_queues_topic_creation_only_once_while_delivery_retries_wait(): void
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

        $job->handle($aiService);

        $draft = AiMessage::query()->sole();
        $pendingDelivery = new SendPendingAiDraftToTelegramJob($draft->id);
        $pendingDelivery->handle(new AiBotApi());
        $pendingDelivery->handle(new AiBotApi());

        $this->assertDatabaseHas('ai_messages', [
            'bot_user_id' => $this->botUser->id,
            'message_id' => null,
            'text_ai' => $aiResponseText,
            'status' => AiMessage::STATUS_PENDING,
        ]);
        Queue::assertPushed(TopicCreateJob::class, 1);
        Queue::assertPushed(SendPendingAiDraftToTelegramJob::class, function (SendPendingAiDraftToTelegramJob $job): bool {
            return $job->aiMessageId === AiMessage::query()->sole()->id;
        });
        Http::assertNothingSent();
    }

    public function test_pending_foreign_language_draft_is_delivered_after_topic_becomes_available(): void
    {
        $this->botUser->update([
            'topic_id' => 4844,
            'preferred_language_code' => 'en',
            'preferred_language_name' => 'English',
        ]);

        $draft = AiMessage::create([
            'bot_user_id' => $this->botUser->id,
            'message_id' => null,
            'text_ai' => 'Перейдите в группу поддержки.',
            'text_source' => 'Перейдите в группу поддержки.',
            'text_translated' => 'Go to the support group.',
            'source_locale' => 'ru',
            'target_locale' => 'en',
            'translation_status' => 'ready',
            'text_manager' => '',
            'status' => AiMessage::STATUS_PENDING,
        ]);

        Http::fake([
            'https://api.telegram.org/bot' . $this->aiToken . '/sendMessage' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 777,
                    'chat' => ['id' => $this->groupId, 'type' => 'supergroup'],
                    'date' => time(),
                    'text' => 'draft',
                ],
            ]),
            'https://api.telegram.org/bot' . $this->aiToken . '/editMessageReplyMarkup' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 777,
                    'chat' => ['id' => $this->groupId, 'type' => 'supergroup'],
                    'date' => time(),
                ],
            ]),
        ]);

        (new SendPendingAiDraftToTelegramJob($draft->id))->handle(new AiBotApi());

        $this->assertSame('777', (string) $draft->refresh()->message_id);
        Http::assertSent(function ($request): bool {
            $text = (string) ($request->data()['text'] ?? '');

            return str_contains($request->url(), '/sendMessage')
                && ($request->data()['message_thread_id'] ?? null) === 4844
                && str_contains($text, 'Для оператора')
                && str_contains($text, 'Клиенту на EN')
                && str_contains($text, 'Go to the support group.');
        });
    }

    public function test_message_too_long_is_deterministic_and_keeps_persisted_draft_pending(): void
    {
        $draft = AiMessage::create([
            'bot_user_id' => $this->botUser->id,
            'message_id' => null,
            'text_ai' => 'Сохранённый ответ оператора',
            'text_source' => 'Сохранённый ответ оператора',
            'source_locale' => 'ru',
            'translation_status' => 'ready',
            'text_manager' => '',
            'status' => AiMessage::STATUS_PENDING,
        ]);

        Http::fake([
            'https://api.telegram.org/bot' . $this->aiToken . '/sendMessage' => Http::response([
                'ok' => false,
                'error_code' => 400,
                'description' => 'Bad Request: message is too long',
            ], 400),
        ]);

        (new SendPendingAiDraftToTelegramJob($draft->id))->handle(new AiBotApi());

        $this->assertDatabaseHas('ai_messages', [
            'id' => $draft->id,
            'message_id' => null,
            'status' => AiMessage::STATUS_PENDING,
            'text_ai' => 'Сохранённый ответ оператора',
        ]);
        $this->assertSame(0, Message::count());
    }

    public function test_long_html_draft_is_split_completely_and_actions_are_attached_to_last_part(): void
    {
        $sourceText = str_repeat('Русский <ответ> & пояснение. ', 220);
        $translatedText = str_repeat('English <answer> & explanation. ', 220);
        $draft = AiMessage::create([
            'bot_user_id' => $this->botUser->id,
            'message_id' => null,
            'text_ai' => $sourceText,
            'text_source' => $sourceText,
            'text_translated' => $translatedText,
            'source_locale' => 'ru',
            'target_locale' => 'en',
            'translation_status' => 'ready',
            'text_manager' => '',
            'status' => AiMessage::STATUS_PENDING,
        ]);
        $nextMessageId = 900;

        Http::fake(function ($request) use (&$nextMessageId) {
            if (str_contains($request->url(), '/editMessageReplyMarkup')) {
                $messageId = $nextMessageId - 1;

                return Http::response([
                    'ok' => true,
                    'result' => [
                        'message_id' => $messageId,
                        'chat' => ['id' => $this->groupId, 'type' => 'supergroup'],
                        'date' => time(),
                    ],
                ]);
            }

            return Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => $nextMessageId++,
                    'chat' => ['id' => $this->groupId, 'type' => 'supergroup'],
                    'date' => time(),
                    'text' => (string) ($request->data()['text'] ?? ''),
                ],
            ]);
        });

        (new SendPendingAiDraftToTelegramJob($draft->id))->handle(new AiBotApi());

        $sendRequests = collect(Http::recorded())
            ->map(fn (array $record) => $record[0])
            ->filter(fn ($request) => str_contains($request->url(), '/sendMessage'))
            ->values();
        $this->assertGreaterThan(1, $sendRequests->count());

        $joinedHtml = '';
        foreach ($sendRequests as $request) {
            $part = (string) $request->data()['text'];
            $this->assertLessThanOrEqual(4096, mb_strlen($part));
            $this->assertSame(substr_count($part, '<b>'), substr_count($part, '</b>'));
            $joinedHtml .= $part;
        }

        $rendered = html_entity_decode(strip_tags($joinedHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertStringContainsString($sourceText, $rendered);
        $this->assertStringContainsString($translatedText, $rendered);

        $lastMessageId = $nextMessageId - 1;
        $this->assertSame((string) $lastMessageId, (string) $draft->refresh()->message_id);
        Http::assertSent(function ($request) use ($lastMessageId): bool {
            $data = $request->data();

            return str_contains($request->url(), '/editMessageReplyMarkup')
                && ($data['message_id'] ?? null) === $lastMessageId
                && ($data['reply_markup']['inline_keyboard'][0][0]['callback_data'] ?? null)
                    === 'ai_message_send_' . $lastMessageId;
        });

        $resolvedDraft = (new AiAcceptMessage())->getMessageDataByCallbackData('ai_message_send_' . $lastMessageId);
        $this->assertSame($draft->id, $resolvedDraft?->id);
        $this->assertSame(0, Message::count());
    }
}

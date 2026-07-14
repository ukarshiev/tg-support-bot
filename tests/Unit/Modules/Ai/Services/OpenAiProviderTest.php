<?php

namespace Tests\Unit\Modules\Ai\Services;

use App\Models\AiKnowledgeItem;
use App\Models\BotUser;
use App\Modules\Ai\DTOs\AiRequestDto;
use App\Modules\Ai\Services\AiAssistantService;
use App\Modules\Ai\Services\AiSystemPromptLoader;
use App\Modules\Ai\Services\OpenAiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class OpenAiProviderTest extends TestCase
{
    use RefreshDatabase;

    private ?BotUser $botUser;

    private string $provider;

    private string $baseProviderUrl;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        app(\App\Services\Settings\SettingsService::class)->set('ai.default_provider', 'openai');
        app(\App\Services\Settings\SettingsService::class)->set('ai.openai_api_key', 'test_123');
        app(\App\Services\Settings\SettingsService::class)->set('ai.openai_base_url', 'https://api.openai.com/v1');

        $loader = Mockery::mock(AiSystemPromptLoader::class);
        $loader->shouldReceive('render')->andReturn('System prompt');
        $this->app->instance(AiSystemPromptLoader::class, $loader);

        $this->botUser = BotUser::getUserByChatId(time(), 'telegram');

        $this->provider = 'openai';
        $this->baseProviderUrl = 'https://api.openai.com/v1';
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_successful_process_message(): void
    {
        $managerTextMessage = 'Напиши приветствие';
        $answerMessage = 'Привет! Я здесь, чтобы помочь тебе с проектом TG Support Bot.';

        Http::fake([
            $this->baseProviderUrl . '/chat/completions' => Http::response([
                'id' => 'chatcmpl-123',
                'object' => 'chat.completion',
                'created' => time(),
                'model' => 'gpt-4o-mini',
                'choices' => [
                    [
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => $answerMessage,
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 42,
                    'completion_tokens' => 18,
                    'total_tokens' => 60,
                ],
            ], 200),
        ]);

        $aiRequest = new AiRequestDto(
            message: $managerTextMessage,
            userId: $this->botUser->id,
            platform: 'telegram',
            provider: $this->provider,
            forceEscalation: false
        );

        $aiService = $this->app->make(AiAssistantService::class);
        $aiResponse = $aiService->processMessage($aiRequest);

        $this->assertEquals($answerMessage, $aiResponse->response);
        $this->assertEquals($this->provider, $aiResponse->provider);
    }

    public function test_payload_messages_have_system_first_history_language_guard_then_current_message(): void
    {
        Http::fake([
            $this->baseProviderUrl . '/chat/completions' => Http::response([
                'choices' => [
                    ['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop'],
                ],
                'usage' => ['total_tokens' => 1],
                'model' => 'gpt-4o-mini',
            ], 200),
        ]);

        $aiRequest = new AiRequestDto(
            message: 'Текущее сообщение',
            userId: $this->botUser->id,
            platform: 'telegram',
            context: [
                ['role' => 'user', 'content' => 'Старое от пользователя'],
                ['role' => 'assistant', 'content' => 'Старый ответ'],
            ],
            provider: $this->provider,
        );

        (new OpenAiProvider())->processMessage($aiRequest);

        Http::assertSent(function ($request) {
            $messages = $request->data()['messages'] ?? [];
            return count($messages) === 5
                && $messages[0]['role'] === 'system'
                && $messages[0]['content'] === 'System prompt'
                && $messages[1] === ['role' => 'user', 'content' => 'Старое от пользователя']
                && $messages[2] === ['role' => 'assistant', 'content' => 'Старый ответ']
                && $messages[3]['role'] === 'system'
                && str_contains($messages[3]['content'], 'определи язык только по последнему сообщению пользователя')
                && $messages[4] === ['role' => 'user', 'content' => 'Текущее сообщение'];
        });
    }

    public function test_payload_uses_selected_language_when_present(): void
    {
        Http::fake([
            $this->baseProviderUrl . '/chat/completions' => Http::response([
                'choices' => [
                    ['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop'],
                ],
                'usage' => ['total_tokens' => 1],
                'model' => 'gpt-4o-mini',
            ], 200),
        ]);

        $aiRequest = new AiRequestDto(
            message: 'What is the price?',
            userId: $this->botUser->id,
            platform: 'telegram',
            provider: $this->provider,
            preferredLanguageCode: 'de',
            preferredLanguageName: 'Deutsch',
        );

        (new OpenAiProvider())->processMessage($aiRequest);

        Http::assertSent(function ($request) {
            $messages = $request->data()['messages'] ?? [];
            $languageRule = $messages[count($messages) - 2]['content'] ?? '';

            return str_contains($languageRule, 'пользователь выбрал язык Deutsch')
                && str_contains($languageRule, 'Отвечай строго на этом языке');
        });
    }

    public function test_ai_service_adds_relevant_knowledge_before_history(): void
    {
        AiKnowledgeItem::create([
            'slug' => 'product-brospace',
            'title' => 'BroSpace',
            'content' => 'BroSpace стоит 500 ₽ за 1 месяц.',
            'keywords' => ['brospace'],
            'priority' => 10,
        ]);

        Http::fake([
            $this->baseProviderUrl . '/chat/completions' => Http::response([
                'choices' => [
                    ['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop'],
                ],
                'usage' => ['total_tokens' => 1],
                'model' => 'gpt-4o-mini',
            ], 200),
        ]);

        $aiRequest = new AiRequestDto(
            message: 'Сколько стоит BroSpace?',
            userId: $this->botUser->id,
            platform: 'telegram',
            provider: $this->provider,
        );

        $this->app->make(AiAssistantService::class)->processMessage($aiRequest);

        Http::assertSent(function ($request) {
            $messages = $request->data()['messages'] ?? [];

            return ($messages[1]['role'] ?? null) === 'system'
                && str_contains($messages[1]['content'] ?? '', 'BroSpace стоит 500 ₽')
                && str_contains($messages[count($messages) - 2]['content'] ?? '', 'определи язык только по последнему сообщению пользователя')
                && $messages[count($messages) - 1] === ['role' => 'user', 'content' => 'Сколько стоит BroSpace?'];
        });
    }
}

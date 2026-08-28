<?php

namespace Tests\Unit\Modules\Ai\Services;

use App\Models\BotUser;
use App\Modules\Ai\DTOs\AiRequestDto;
use App\Modules\Ai\Services\AiAssistantService;
use App\Modules\Ai\Services\AiSystemPromptLoader;
use App\Modules\Ai\Services\DeepSeekProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class DeepSeekProviderTest extends TestCase
{
    use RefreshDatabase;

    private ?BotUser $botUser;

    private string $provider;

    private string $baseProviderUrl;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        app(\App\Services\Settings\SettingsService::class)->set('ai.default_provider', 'deepseek');
        app(\App\Services\Settings\SettingsService::class)->set('ai.deepseek_client_secret', 'test_123');
        app(\App\Services\Settings\SettingsService::class)->set('ai.deepseek_base_url', 'https://api.deepseek.com/chat/completions');

        $loader = Mockery::mock(AiSystemPromptLoader::class);
        $loader->shouldReceive('render')->andReturn('System prompt');
        $this->app->instance(AiSystemPromptLoader::class, $loader);

        $chatId = time();
        $this->botUser = BotUser::getUserByChatId($chatId, 'telegram');

        $this->provider = 'deepseek';
        $this->baseProviderUrl = 'https://api.deepseek.com/chat/completions';
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
            $this->baseProviderUrl => Http::response([
                'id' => 'chatcmpl-test',
                'object' => 'chat.completion',
                'created' => time(),
                'model' => 'deepseek-chat',
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
                    'prompt_tokens' => 120,
                    'completion_tokens' => 20,
                    'total_tokens' => 140,
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
            $this->baseProviderUrl => Http::response([
                'choices' => [
                    ['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop'],
                ],
                'usage' => ['total_tokens' => 1],
                'model' => 'deepseek-chat',
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

        (new DeepSeekProvider())->processMessage($aiRequest);

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

    public function test_fallback_to_v4_model_when_deprecated_deepseek_chat_is_configured(): void
    {
        $settings = app(\App\Services\Settings\SettingsService::class);
        $settings->set('ai.deepseek_model', 'deepseek-chat');

        Http::fake([
            $this->baseProviderUrl => Http::response([
                'choices' => [
                    [
                        'index' => 0,
                        'message' => ['role' => 'assistant', 'content' => 'ok'],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => ['total_tokens' => 10],
                'model' => 'deepseek-v4-pro',
            ], 200),
        ]);

        $aiRequest = new AiRequestDto(
            message: 'test',
            userId: $this->botUser->id,
            platform: 'telegram',
            provider: $this->provider,
        );

        (new DeepSeekProvider())->processMessage($aiRequest);

        Http::assertSent(function ($request) {
            $body = $request->data();
            return $body['model'] === 'deepseek-v4-pro';
        });
    }

    public function test_uses_reasoning_content_when_content_is_empty(): void
    {
        $settings = app(\App\Services\Settings\SettingsService::class);
        $settings->set('ai.deepseek_model', 'deepseek-v4-pro');

        $expected = 'Ответ из reasoning_content';

        Http::fake([
            $this->baseProviderUrl => Http::response([
                'choices' => [
                    [
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => '',
                            'reasoning_content' => $expected,
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => ['total_tokens' => 15],
                'model' => 'deepseek-v4-pro',
            ], 200),
        ]);

        $aiRequest = new AiRequestDto(
            message: 'Как дела?',
            userId: $this->botUser->id,
            platform: 'telegram',
            provider: $this->provider,
        );

        $aiResponse = (new DeepSeekProvider())->processMessage($aiRequest);

        $this->assertNotNull($aiResponse);
        $this->assertSame($expected, $aiResponse->response);
    }
}

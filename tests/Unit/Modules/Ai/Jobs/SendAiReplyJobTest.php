<?php

namespace Tests\Unit\Modules\Ai\Jobs;

use App\Models\AiMessage;
use App\Models\BotUser;
use App\Models\Message;
use App\Modules\Ai\DTOs\AiResponseDto;
use App\Modules\Ai\Jobs\DeliverAiMessageJob;
use App\Modules\Ai\Jobs\SendAiReplyJob;
use App\Modules\Ai\Services\AiAssistantService;
use App\Modules\Ai\Services\AiBotApi;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SendAiReplyJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::fake(['*' => Http::response(['ok' => true, 'result' => true])]);
        app(SettingsService::class)->set('ai.default_provider', 'openai');
        app(SettingsService::class)->set('telegram.token', null);
    }

    public function test_generation_creates_pending_delivery_and_never_prematurely_accepts(): void
    {
        $botUser = BotUser::create([
            'chat_id' => 701,
            'platform' => 'telegram',
            'preferred_language_code' => 'ru',
        ]);
        $service = $this->aiService('Ответ пользователю');

        (new SendAiReplyJob($botUser->id, null, 'Вопрос'))->handle(new AiBotApi(), $service);

        $aiMessage = AiMessage::firstOrFail();
        $this->assertSame('delivery_pending', $aiMessage->status);
        $this->assertSame('Ответ пользователю', $aiMessage->text_translated);
        $this->assertSame(0, Message::count());
        Queue::assertPushed(DeliverAiMessageJob::class, fn ($job): bool => $job->aiMessageId === $aiMessage->id);
    }

    public function test_missing_locale_uses_builtin_english_instead_of_russian_source(): void
    {
        $botUser = BotUser::create(['chat_id' => 702, 'platform' => 'vk']);

        (new SendAiReplyJob($botUser->id, null, 'Question'))
            ->handle(new AiBotApi(), $this->aiService('Русский ответ'));

        $aiMessage = AiMessage::firstOrFail();
        $this->assertSame('builtin_safe_english', $aiMessage->translation_provider);
        $this->assertSame('ready', $aiMessage->translation_status);
        $this->assertSame(
            'A support agent will reply shortly. We could not prepare a safe localized answer.',
            $aiMessage->text_translated,
        );
        $this->assertNotSame($aiMessage->text_source, $aiMessage->text_translated);
    }

    public function test_transient_generation_exception_is_rethrown_for_queue_retry(): void
    {
        $botUser = BotUser::create([
            'chat_id' => 703,
            'platform' => 'telegram',
            'preferred_language_code' => 'ru',
        ]);
        $service = $this->createMock(AiAssistantService::class);
        $service->method('processMessage')->willThrowException(new \RuntimeException('provider timeout'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('provider timeout');

        (new SendAiReplyJob($botUser->id, null, 'Вопрос'))->handle(new AiBotApi(), $service);
    }

    private function aiService(string $response): AiAssistantService
    {
        $dto = new AiResponseDto(
            response: $response,
            confidenceScore: 0.9,
            shouldEscalate: false,
            provider: 'openai',
            modelUsed: 'test',
            tokensUsed: 10,
            responseTime: 0.1,
        );
        $service = $this->createMock(AiAssistantService::class);
        $service->method('processMessage')->willReturn($dto);

        return $service;
    }
}

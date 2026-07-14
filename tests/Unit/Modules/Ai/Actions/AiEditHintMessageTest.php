<?php

namespace Tests\Unit\Modules\Ai\Actions;

use App\Modules\Ai\Actions\AiEditHintMessage;
use App\Modules\Telegram\DTOs\TelegramUpdateDto;
use App\Modules\Telegram\Jobs\SendTelegramSimpleQueryJob;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AiEditHintMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_edit_button_answers_callback_with_reply_instruction(): void
    {
        Queue::fake();
        app(SettingsService::class)->set('telegram_ai.token', 'test-token');

        $dto = new TelegramUpdateDto(
            updateId: 1,
            typeQuery: 'callback_query',
            aiTechMessage: false,
            typeSource: 'supergroup',
            callbackId: '777',
            callbackData: 'ai_message_edit_123',
        );

        (new AiEditHintMessage())->execute($dto);

        Queue::assertPushed(SendTelegramSimpleQueryJob::class, function (SendTelegramSimpleQueryJob $job) {
            return $job->queryParams->methodQuery === 'answerCallbackQuery'
                && (string) $job->queryParams->callback_query_id === '777'
                && $job->queryParams->text === 'Ответьте reply на AI-подсказку своим текстом — он уйдёт клиенту.';
        });
    }
}

<?php

namespace Tests\Unit\Modules\Telegram\Services\Commands;

use App\Modules\Telegram\DTOs\TelegramUpdateDto;
use App\Modules\Telegram\Jobs\SendTelegramSimpleQueryJob;
use App\Modules\Telegram\Services\Commands\AutoAiModeCommand;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AutoAiModeCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $settings = app(SettingsService::class);
        $settings->set('telegram.token', 'test-token');
        $settings->set('telegram.group_id', '-1003546470853');
        $settings->set('ai.auto_reply', false);
    }

    public function test_admin_can_enable_auto_ai_from_general_topic(): void
    {
        Queue::fake();
        $this->fakeTelegramAdmin('administrator');

        $handled = app(AutoAiModeCommand::class)->handleIfCommand($this->generalUpdate('/autoAi on'));

        $this->assertTrue($handled);
        $this->assertTrue((bool) app(SettingsService::class)->get('ai.auto_reply'));
        $this->assertQueuedReply('Auto AI: ON — AI отвечает клиентам сам.', null);
    }

    public function test_admin_can_disable_auto_ai_from_general_topic(): void
    {
        Queue::fake();
        $this->fakeTelegramAdmin('creator');
        app(SettingsService::class)->set('ai.auto_reply', true);

        $handled = app(AutoAiModeCommand::class)->handleIfCommand($this->generalUpdate('autoai off'));

        $this->assertTrue($handled);
        $this->assertFalse((bool) app(SettingsService::class)->get('ai.auto_reply'));
        $this->assertQueuedReply('Auto AI: OFF — AI пишет только внутренние подсказки.', null);
    }

    public function test_admin_can_read_auto_ai_status_from_general_topic(): void
    {
        Queue::fake();
        $this->fakeTelegramAdmin('administrator');
        app(SettingsService::class)->set('ai.auto_reply', true);

        $handled = app(AutoAiModeCommand::class)->handleIfCommand($this->generalUpdate('autoai status'));

        $this->assertTrue($handled);
        $this->assertQueuedReply('Auto AI: ON — AI отвечает клиентам сам.', null);
    }

    public function test_admin_command_without_thread_id_is_treated_as_general_topic(): void
    {
        Queue::fake();
        $this->fakeTelegramAdmin('administrator');

        $handled = app(AutoAiModeCommand::class)->handleIfCommand($this->telegramUpdate('/autoAi status', null));

        $this->assertTrue($handled);
        $this->assertQueuedReply('Auto AI: OFF — AI пишет только внутренние подсказки.', null);
    }

    public function test_non_admin_gets_denied_and_setting_is_not_changed(): void
    {
        Queue::fake();
        $this->fakeTelegramAdmin('member');

        $handled = app(AutoAiModeCommand::class)->handleIfCommand($this->generalUpdate('/autoAi on'));

        $this->assertTrue($handled);
        $this->assertFalse((bool) app(SettingsService::class)->get('ai.auto_reply'));
        $this->assertQueuedReply('Команда доступна только администраторам группы.', null);
    }

    public function test_command_in_client_topic_is_consumed_and_not_sent_to_client(): void
    {
        Queue::fake();

        $handled = app(AutoAiModeCommand::class)->handleIfCommand($this->clientTopicUpdate('/autoAi on'));

        $this->assertTrue($handled);
        $this->assertFalse((bool) app(SettingsService::class)->get('ai.auto_reply'));
        $this->assertQueuedReply('Команда Auto AI доступна только в теме General.', 777);
    }

    public function test_non_auto_ai_text_is_not_handled(): void
    {
        Queue::fake();

        $handled = app(AutoAiModeCommand::class)->handleIfCommand($this->generalUpdate('autoaimaybe on'));

        $this->assertFalse($handled);
        Queue::assertNothingPushed();
    }

    private function fakeTelegramAdmin(string $status): void
    {
        Http::fake([
            'https://api.telegram.org/bottest-token/getChatMember' => Http::response([
                'ok' => true,
                'result' => [
                    'status' => $status,
                ],
            ]),
        ]);
    }

    private function generalUpdate(string $text): TelegramUpdateDto
    {
        return $this->telegramUpdate($text, 1);
    }

    private function clientTopicUpdate(string $text): TelegramUpdateDto
    {
        return $this->telegramUpdate($text, 777);
    }

    private function telegramUpdate(string $text, ?int $threadId): TelegramUpdateDto
    {
        return new TelegramUpdateDto(
            updateId: 100,
            typeQuery: 'message',
            aiTechMessage: false,
            typeSource: 'supergroup',
            isBot: false,
            chatId: -1003546470853,
            messageThreadId: $threadId,
            messageId: 55,
            text: $text,
            rawData: [
                'message' => [
                    'from' => [
                        'id' => 12345,
                    ],
                ],
            ],
        );
    }

    private function assertQueuedReply(string $text, ?int $threadId): void
    {
        Queue::assertPushed(SendTelegramSimpleQueryJob::class, function (SendTelegramSimpleQueryJob $job) use ($text, $threadId) {
            return $job->queryParams->methodQuery === 'sendMessage'
                && $job->queryParams->chat_id === '-1003546470853'
                && $job->queryParams->message_thread_id === $threadId
                && $job->queryParams->text === $text;
        });
    }
}

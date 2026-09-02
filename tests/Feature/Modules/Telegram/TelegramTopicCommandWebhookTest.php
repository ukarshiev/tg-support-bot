<?php

namespace Tests\Feature\Modules\Telegram;

use App\Models\BotUser;
use App\Models\Message;
use App\Modules\Telegram\Jobs\SendTelegramMessageJob;
use App\Modules\Telegram\Jobs\SendTelegramSimpleQueryJob;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TelegramTopicCommandWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const GROUP_ID = -1003546470853;

    private BotUser $botUser;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);

        $settings = app(SettingsService::class);
        $settings->set('telegram.secret_key', 'secret');
        $settings->set('telegram.token', 'test-token');
        $settings->set('telegram.group_id', (string) self::GROUP_ID);

        $this->botUser = BotUser::create([
            'chat_id' => '777001',
            'platform' => 'telegram',
            'topic_id' => 444,
            'preferred_language_code' => 'ru',
            'preferred_language_name' => 'Русский',
        ]);
    }

    public function test_ban_command_bans_user_without_forwarding_command_to_client(): void
    {
        $this->topicMessage('/ban')->assertOk();

        $this->assertTrue($this->botUser->fresh()->isBanned());
        $this->assertCommandWasNotForwarded('/ban');
        $this->assertTopicConfirmationContains(__('messages.ban_status_message'));
    }

    public function test_unban_command_unbans_user_and_confirms_result(): void
    {
        $this->botUser->update([
            'is_banned' => true,
            'banned_at' => now(),
            'is_closed' => true,
            'closed_at' => now(),
        ]);

        $this->topicMessage('/unban')->assertOk();

        $this->assertFalse($this->botUser->fresh()->isBanned());
        $this->assertNull($this->botUser->fresh()->banned_at);
        $this->assertCommandWasNotForwarded('/unban');
        $this->assertTopicConfirmationContains(__('messages.unban_status_message'));
    }

    public function test_ban_command_with_bot_mention_is_recognized(): void
    {
        $this->topicMessage('/ban@relaxaclub_support')->assertOk();

        $this->assertTrue($this->botUser->fresh()->isBanned());
        $this->assertCommandWasNotForwarded('/ban@relaxaclub_support');
    }

    public function test_unknown_topic_command_is_not_forwarded_and_shows_supported_commands(): void
    {
        $this->topicMessage('/status')->assertOk();

        $this->assertCommandWasNotForwarded('/status');
        Queue::assertPushed(SendTelegramSimpleQueryJob::class, function (SendTelegramSimpleQueryJob $job): bool {
            $text = (string) $job->queryParams->text;

            return (string) $job->queryParams->chat_id === (string) self::GROUP_ID
                && $job->queryParams->message_thread_id === 444
                && str_contains($text, '/contact')
                && str_contains($text, '/ai_generate')
                && str_contains($text, '/ban')
                && str_contains($text, '/unban');
        });
    }

    public function test_regular_manager_text_is_still_forwarded_to_client(): void
    {
        $this->topicMessage('Обычный ответ менеджера')->assertOk();

        Queue::assertPushed(SendTelegramMessageJob::class, function (SendTelegramMessageJob $job): bool {
            return $job->typeMessage === 'outgoing'
                && $job->updateDto->text === 'Обычный ответ менеджера'
                && (string) $job->queryParams->chat_id === $this->botUser->chat_id;
        });
    }

    public function test_private_start_command_keeps_existing_language_selector_flow(): void
    {
        $this->privateMessage('/start')->assertOk();

        Queue::assertPushed(SendTelegramMessageJob::class, function (SendTelegramMessageJob $job): bool {
            return $job->typeMessage === 'outgoing'
                && $job->updateDto->typeSource === 'private'
                && $job->updateDto->text === '/start'
                && (string) $job->queryParams->chat_id === $this->botUser->chat_id
                && $job->queryParams->reply_markup !== null;
        });
    }

    private function assertCommandWasNotForwarded(string $command): void
    {
        Queue::assertNotPushed(SendTelegramMessageJob::class, function (SendTelegramMessageJob $job) use ($command): bool {
            return $job->typeMessage === 'outgoing'
                && $job->updateDto->text === $command
                && (string) $job->queryParams->chat_id === $this->botUser->chat_id;
        });
        Queue::assertNotPushed(SendTelegramSimpleQueryJob::class, function (SendTelegramSimpleQueryJob $job) use ($command): bool {
            return (string) $job->queryParams->chat_id === $this->botUser->chat_id
                && $job->queryParams->text === $command;
        });

        $this->assertFalse(Message::query()
            ->where('bot_user_id', $this->botUser->id)
            ->where('message_type', 'outgoing')
            ->where('text', $command)
            ->exists());
    }

    private function assertTopicConfirmationContains(string $status): void
    {
        Queue::assertPushed(SendTelegramSimpleQueryJob::class, function (SendTelegramSimpleQueryJob $job) use ($status): bool {
            return (string) $job->queryParams->chat_id === (string) self::GROUP_ID
                && $job->queryParams->message_thread_id === 444
                && str_contains((string) $job->queryParams->text, $status);
        });
    }

    private function topicMessage(string $text): \Illuminate\Testing\TestResponse
    {
        return $this->telegramWebhook([
            'message_id' => random_int(100, 9999),
            'message_thread_id' => 444,
            'text' => $text,
            'from' => [
                'id' => 12345,
                'is_bot' => false,
                'first_name' => 'Manager',
            ],
            'chat' => [
                'id' => self::GROUP_ID,
                'type' => 'supergroup',
            ],
        ]);
    }

    private function privateMessage(string $text): \Illuminate\Testing\TestResponse
    {
        return $this->telegramWebhook([
            'message_id' => random_int(100, 9999),
            'text' => $text,
            'from' => [
                'id' => (int) $this->botUser->chat_id,
                'is_bot' => false,
                'first_name' => 'Client',
            ],
            'chat' => [
                'id' => (int) $this->botUser->chat_id,
                'type' => 'private',
            ],
        ]);
    }

    private function telegramWebhook(array $message): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/telegram/bot', [
            'update_id' => random_int(10000, 99999),
            'message' => $message,
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => 'secret',
        ]);
    }
}

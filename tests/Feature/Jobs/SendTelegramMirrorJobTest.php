<?php

namespace Tests\Feature\Jobs;

use App\Models\BotUser;
use App\Models\DeliveryOperation;
use App\Models\Message;
use App\Modules\Telegram\Jobs\SendTelegramMirrorJob;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Mocks\Tg\TelegramUpdateDtoMock;
use Tests\TestCase;

class SendTelegramMirrorJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_mirror_is_idempotent_and_uses_dedicated_queue(): void
    {
        app(SettingsService::class)->set('telegram.group_id', '-100123456789');
        app(SettingsService::class)->set('telegram.token', 'test-token');

        $botUser = BotUser::getOrCreateByTelegramUpdate(TelegramUpdateDtoMock::getDto());
        $botUser->update(['topic_id' => 777]);
        $message = Message::create([
            'bot_user_id' => $botUser->id,
            'platform' => 'telegram',
            'message_type' => 'outgoing',
            'from_id' => 100,
            'to_id' => 200,
            'text' => 'Приветствие',
        ]);

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 999, 'chat' => ['id' => -100123456789]],
            ]),
        ]);

        $job = new SendTelegramMirrorJob($botUser->id, $message->id, "🤖 Бот клиенту:\nПриветствие", 'telegram:update:100');
        $this->assertSame('telegram-mirror', $job->queue);

        $job->handle();
        $job->handle();

        Http::assertSentCount(1);
        $this->assertDatabaseHas('delivery_operations', [
            'bot_user_id' => $botUser->id,
            'message_id' => $message->id,
            'status' => DeliveryOperation::STATUS_DELIVERED,
            'external_message_id' => 999,
        ]);
    }

    public function test_incoming_photo_is_mirrored_as_photo_with_caption_and_topic(): void
    {
        app(SettingsService::class)->set('telegram.group_id', '-100123456789');
        app(SettingsService::class)->set('telegram.token', 'test-token');

        $botUser = BotUser::getOrCreateByTelegramUpdate(TelegramUpdateDtoMock::getDto());
        $botUser->update(['topic_id' => 888]);
        $message = Message::create([
            'bot_user_id' => $botUser->id,
            'platform' => 'telegram',
            'message_type' => 'incoming',
            'from_id' => 101,
            'to_id' => 0,
            'text' => 'Подпись клиента',
        ]);
        $message->attachments()->create(['file_id' => 'photo-file', 'file_type' => 'photo']);

        Http::fake(['https://api.telegram.org/*' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 1000, 'chat' => ['id' => -100123456789]],
        ])]);

        (new SendTelegramMirrorJob($botUser->id, $message->id, 'Подпись клиента', 'telegram:update:101'))->handle();

        Http::assertSent(fn ($request): bool =>
            str_ends_with($request->url(), '/sendPhoto')
            && (int) $request['message_thread_id'] === 888
            && $request['photo'] === 'photo-file'
            && $request['caption'] === 'Подпись клиента');
    }

    public function test_mirror_without_topic_schedules_topic_creation_and_never_calls_general(): void
    {
        Queue::fake();
        app(SettingsService::class)->set('telegram.group_id', '-100123456789');
        app(SettingsService::class)->set('telegram.token', 'test-token');

        $botUser = BotUser::getOrCreateByTelegramUpdate(TelegramUpdateDtoMock::getDto());
        $botUser->update(['topic_id' => null]);
        $message = Message::create([
            'bot_user_id' => $botUser->id,
            'platform' => 'telegram',
            'message_type' => 'incoming',
            'from_id' => 102,
            'to_id' => 0,
            'text' => 'Не в General',
        ]);

        Http::fake();
        (new SendTelegramMirrorJob($botUser->id, $message->id, 'Не в General', 'telegram:update:102'))->handle();

        Http::assertNothingSent();
        Queue::assertPushed(\App\Modules\Telegram\Jobs\TopicCreateJob::class);
        $this->assertDatabaseHas('delivery_operations', [
            'message_id' => $message->id,
            'status' => DeliveryOperation::STATUS_RETRYING,
        ]);
    }

    public function test_closed_topic_is_reopened_before_incoming_message_is_mirrored(): void
    {
        app(SettingsService::class)->set('telegram.group_id', '-100123456789');
        app(SettingsService::class)->set('telegram.token', 'test-token');

        $botUser = BotUser::getOrCreateByTelegramUpdate(TelegramUpdateDtoMock::getDto());
        $botUser->update(['topic_id' => 999, 'is_closed' => false, 'closed_at' => null]);
        Cache::put("telegram:topic-reopen:{$botUser->id}", true, now()->addMinute());
        $message = Message::create([
            'bot_user_id' => $botUser->id,
            'platform' => 'telegram',
            'message_type' => 'incoming',
            'from_id' => 103,
            'to_id' => 0,
            'text' => 'Новое обращение',
        ]);

        Http::fake(['https://api.telegram.org/*' => Http::sequence()
            ->push(['ok' => true, 'result' => true])
            ->push(['ok' => true, 'result' => ['message_id' => 1001, 'chat' => ['id' => -100123456789]]])]);

        (new SendTelegramMirrorJob($botUser->id, $message->id, 'Новое обращение', 'telegram:update:103'))->handle();

        Http::assertSentCount(2);
        $requests = Http::recorded();
        $this->assertStringEndsWith('/reopenForumTopic', $requests[0][0]->url());
        $this->assertStringEndsWith('/sendMessage', $requests[1][0]->url());
        $this->assertSame(999, (int) $requests[1][0]['message_thread_id']);
        $this->assertFalse(Cache::has("telegram:topic-reopen:{$botUser->id}"));
    }

    public function test_deleted_topic_is_cleared_and_recreated_without_general_delivery(): void
    {
        Queue::fake();
        app(SettingsService::class)->set('telegram.group_id', '-100123456789');
        app(SettingsService::class)->set('telegram.token', 'test-token');
        $botUser = BotUser::getOrCreateByTelegramUpdate(TelegramUpdateDtoMock::getDto());
        $botUser->update(['topic_id' => 111]);
        $message = Message::create([
            'bot_user_id' => $botUser->id,
            'platform' => 'telegram',
            'message_type' => 'incoming',
            'from_id' => 104,
            'to_id' => 0,
            'text' => 'Восстановить тему',
        ]);

        Http::fake(['https://api.telegram.org/*' => Http::response([
            'ok' => false,
            'error_code' => 400,
            'description' => 'Bad Request: message thread not found',
        ], 400)]);

        (new SendTelegramMirrorJob($botUser->id, $message->id, $message->text, 'telegram:update:104'))->handle();

        $this->assertNull($botUser->refresh()->topic_id);
        Queue::assertPushed(\App\Modules\Telegram\Jobs\TopicCreateJob::class);
        Http::assertSentCount(1);
    }

    public function test_transient_telegram_failure_is_retried_and_exhaustion_is_visible(): void
    {
        app(SettingsService::class)->set('telegram.group_id', '-100123456789');
        app(SettingsService::class)->set('telegram.token', 'test-token');
        $botUser = BotUser::getOrCreateByTelegramUpdate(TelegramUpdateDtoMock::getDto());
        $botUser->update(['topic_id' => 222]);
        $message = Message::create([
            'bot_user_id' => $botUser->id,
            'platform' => 'telegram',
            'message_type' => 'incoming',
            'from_id' => 105,
            'to_id' => 0,
            'text' => 'Повторить доставку',
        ]);

        Http::fake(['https://api.telegram.org/*' => Http::response([
            'ok' => false,
            'error_code' => 500,
            'description' => 'Internal Server Error',
        ], 500)]);

        $job = new SendTelegramMirrorJob($botUser->id, $message->id, $message->text, 'telegram:update:105');

        try {
            $job->handle();
            $this->fail('Transient Telegram error must escape the job for queue retry.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('code=500', $exception->getMessage());
        }

        $this->assertDatabaseHas('delivery_operations', [
            'message_id' => $message->id,
            'status' => DeliveryOperation::STATUS_RETRYING,
        ]);

        $job->failed(new \RuntimeException('retries exhausted'));
        $this->assertDatabaseHas('delivery_operations', [
            'message_id' => $message->id,
            'status' => DeliveryOperation::STATUS_FAILED,
        ]);
    }
}

<?php

namespace Tests\Unit\Modules\Admin\Jobs;

use App\Models\BotUser;
use App\Models\DeliveryOperation;
use App\Models\Message;
use App\Modules\Admin\Jobs\MirrorAdminReplyToGroupJob;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MirrorAdminReplyToGroupJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(SettingsService::class)->set('telegram.token', 'main-token');
        app(SettingsService::class)->set('telegram.group_id', '-100123');
    }

    public function test_api_failure_is_rethrown_for_queue_retry(): void
    {
        $botUser = BotUser::create([
            'chat_id' => 1001,
            'platform' => 'telegram',
            'topic_id' => 77,
        ]);
        Http::fake(['*' => Http::response([
            'ok' => false,
            'error_code' => 500,
            'description' => 'temporary error',
        ], 500)]);

        $this->expectException(\RuntimeException::class);

        (new MirrorAdminReplyToGroupJob($botUser->id, 'Ответ'))->handle();
    }

    public function test_successful_mirror_targets_only_users_topic(): void
    {
        $botUser = BotUser::create([
            'chat_id' => 1002,
            'platform' => 'telegram',
            'topic_id' => 88,
        ]);
        Http::fake(['*' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 99, 'chat' => ['id' => -100123]],
        ])]);

        (new MirrorAdminReplyToGroupJob($botUser->id, 'Ответ'))->handle();

        Http::assertSent(function ($request): bool {
            return (string) ($request->data()['chat_id'] ?? '') === '-100123'
                && ($request->data()['message_thread_id'] ?? null) === 88;
        });
    }

    public function test_receipt_only_job_confirms_preceding_client_delivery_without_mirror(): void
    {
        $botUser = BotUser::create(['chat_id' => 1003, 'platform' => 'vk']);
        $message = Message::create([
            'bot_user_id' => $botUser->id,
            'platform' => 'vk',
            'message_type' => 'outgoing',
            'from_id' => 0,
            'to_id' => 0,
            'text' => 'Ответ',
        ]);
        DeliveryOperation::create([
            'operation_key' => hash('sha256', 'admin-reply:' . $message->id),
            'bot_user_id' => $botUser->id,
            'message_id' => $message->id,
            'trace_id' => 'test',
            'destination' => 'vk-client',
            'operation' => 'admin-reply',
            'status' => DeliveryOperation::STATUS_PENDING,
        ]);
        Http::fake();

        (new MirrorAdminReplyToGroupJob(
            $botUser->id,
            'Ответ',
            sourceMessageId: $message->id,
            mirrorEnabled: false,
        ))->handle();

        $this->assertDatabaseHas('delivery_operations', [
            'message_id' => $message->id,
            'status' => DeliveryOperation::STATUS_DELIVERED,
        ]);
        Http::assertNothingSent();
    }
}

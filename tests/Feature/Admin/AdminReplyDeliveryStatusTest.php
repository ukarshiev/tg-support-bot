<?php

namespace Tests\Feature\Admin;

use App\Models\BotUser;
use App\Models\DeliveryOperation;
use App\Models\Message;
use App\Modules\Admin\Jobs\NotifyAdminReplyDeliveryFailedJob;
use App\Modules\Admin\Services\AdminReplyDeliveryService;
use App\Modules\Admin\Services\AdminReplyFailureNotifier;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminReplyDeliveryStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_delivery_operation_success_marks_message_delivered(): void
    {
        [$message, $operation] = $this->pendingReply();

        app(AdminReplyDeliveryService::class)->markDelivered($operation, $message, 12345);

        $this->assertDatabaseHas('delivery_operations', [
            'id' => $operation->id,
            'status' => DeliveryOperation::STATUS_DELIVERED,
            'external_message_id' => 12345,
        ]);
        $this->assertDatabaseHas('messages', [
            'id' => $message->id,
            'delivery_status' => Message::DELIVERY_DELIVERED,
        ]);
    }

    public function test_terminal_failure_marks_message_failed_and_queues_one_topic_notification(): void
    {
        Queue::fake();
        [$message, $operation] = $this->pendingReply(topicId: 777);
        $service = app(AdminReplyDeliveryService::class);

        $service->markFailed($operation, 'code=403 type=USER_BLOCKED');
        $service->markFailed($operation->refresh(), 'code=403 type=USER_BLOCKED');

        $this->assertDatabaseHas('messages', [
            'id' => $message->id,
            'delivery_status' => Message::DELIVERY_FAILED,
        ]);
        $this->assertDatabaseHas('delivery_operations', [
            'id' => $operation->id,
            'status' => DeliveryOperation::STATUS_FAILED,
            'last_error' => 'code=403 type=USER_BLOCKED',
        ]);
        Queue::assertPushed(NotifyAdminReplyDeliveryFailedJob::class, 1);
        $this->assertDatabaseCount('delivery_operations', 2);
    }

    public function test_failure_without_existing_topic_does_not_create_or_notify_topic(): void
    {
        Queue::fake();
        [, $operation] = $this->pendingReply(topicId: null);

        app(AdminReplyDeliveryService::class)->markFailed($operation, 'No recipient');

        Queue::assertNotPushed(NotifyAdminReplyDeliveryFailedJob::class);
        $this->assertDatabaseCount('delivery_operations', 1);
    }

    public function test_non_admin_client_delivery_failure_queues_topic_notification(): void
    {
        Queue::fake();
        [, $operation] = $this->pendingReply(
            topicId: 779,
            operationName: 'sendMessage',
        );

        app(AdminReplyDeliveryService::class)->markFailed($operation, 'code=403 type=USER_BLOCKED');

        Queue::assertPushed(NotifyAdminReplyDeliveryFailedJob::class, 1);
        $this->assertDatabaseHas('delivery_operations', [
            'operation' => 'admin-reply-failure-notification',
            'message_id' => $operation->message_id,
            'status' => DeliveryOperation::STATUS_PENDING,
        ]);
    }

    public function test_reconcile_command_fails_stale_processing_reply_and_queues_notification(): void
    {
        Queue::fake();
        [$message, $operation] = $this->pendingReply(
            topicId: 778,
            operationName: 'sendDocument',
        );
        $operation->update([
            'status' => DeliveryOperation::STATUS_PROCESSING,
            'started_at' => now()->subMinutes(31),
        ]);

        $this->artisan('delivery:reconcile-admin-replies', ['--minutes' => 30])
            ->expectsOutput('Reconciled stale client and support mirror deliveries: 1')
            ->assertSuccessful();

        $this->assertDatabaseHas('delivery_operations', [
            'id' => $operation->id,
            'status' => DeliveryOperation::STATUS_FAILED,
        ]);
        $this->assertDatabaseHas('messages', [
            'id' => $message->id,
            'delivery_status' => Message::DELIVERY_FAILED,
        ]);
        Queue::assertPushed(NotifyAdminReplyDeliveryFailedJob::class, 1);
    }

    public function test_incoming_support_mirror_failure_queues_distinct_notification_with_time_and_attachment(): void
    {
        Queue::fake();
        app(SettingsService::class)->set('telegram.token', 'test-token');
        app(SettingsService::class)->set('telegram.group_id', '-100123456789');
        $botUser = BotUser::create([
            'chat_id' => 880001,
            'platform' => 'max',
            'topic_id' => 881,
        ]);
        $message = Message::create([
            'bot_user_id' => $botUser->id,
            'platform' => 'max',
            'message_type' => 'incoming',
            'from_id' => 501,
            'to_id' => 0,
            'text' => 'Документ клиента',
        ]);
        $message->forceFill(['created_at' => '2026-08-24 13:45:00'])->save();
        $message->attachments()->create([
            'file_id' => 'document-file',
            'file_type' => 'document',
        ]);
        $failedOperation = DeliveryOperation::create([
            'operation_key' => hash('sha256', 'max-mirror-failed'),
            'bot_user_id' => $botUser->id,
            'message_id' => $message->id,
            'trace_id' => 'max:event:501',
            'destination' => 'telegram-support-topic',
            'operation' => 'sendDocument',
            'status' => DeliveryOperation::STATUS_FAILED,
            'last_error' => 'code=500 type=INTERNAL',
        ]);

        $notifier = app(AdminReplyFailureNotifier::class);
        $this->assertTrue($notifier->queue($failedOperation));
        $this->assertFalse($notifier->queue($failedOperation));
        Queue::assertPushed(NotifyAdminReplyDeliveryFailedJob::class, 1);

        $notification = DeliveryOperation::where('operation', 'support-mirror-failure-notification')->firstOrFail();
        Http::fake(['https://api.telegram.org/*' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 9901, 'chat' => ['id' => -100123456789]],
        ])]);

        (new NotifyAdminReplyDeliveryFailedJob($failedOperation->id, $notification->id))->handle();

        Http::assertSent(fn ($request): bool =>
            $request['message_thread_id'] === 881
            && str_contains($request['text'], 'Входящее сообщение клиента не отображено в теме')
            && str_contains($request['text'], '24.08.2026 13:45')
            && str_contains($request['text'], 'Вложение: файл')
            && ! str_contains($request['text'], 'Ответ клиенту не доставлен'));
    }

    public function test_failure_notification_cannot_recursively_queue_another_notification(): void
    {
        Queue::fake();
        [$message, $sourceOperation] = $this->pendingReply(topicId: 882);
        $notification = DeliveryOperation::create([
            'operation_key' => hash('sha256', 'failed-notification'),
            'bot_user_id' => $sourceOperation->bot_user_id,
            'message_id' => $message->id,
            'trace_id' => 'support-mirror-failure:failed-notification',
            'destination' => 'telegram-topic',
            'operation' => 'support-mirror-failure-notification',
            'status' => DeliveryOperation::STATUS_FAILED,
        ]);

        $this->assertFalse(app(AdminReplyFailureNotifier::class)->queue($notification));
        Queue::assertNotPushed(NotifyAdminReplyDeliveryFailedJob::class);
    }

    public function test_reconcile_command_fails_stale_support_mirror_and_queues_notification(): void
    {
        Queue::fake();
        $botUser = BotUser::create([
            'chat_id' => 880002,
            'platform' => 'vk',
            'topic_id' => 883,
        ]);
        $message = Message::create([
            'bot_user_id' => $botUser->id,
            'platform' => 'vk',
            'message_type' => 'incoming',
            'from_id' => 502,
            'to_id' => 0,
            'text' => 'Зависшее зеркало',
        ]);
        $operation = DeliveryOperation::create([
            'operation_key' => hash('sha256', 'stale-support-mirror'),
            'bot_user_id' => $botUser->id,
            'message_id' => $message->id,
            'trace_id' => 'vk:event:502',
            'destination' => 'telegram-support-topic',
            'operation' => 'sendMessage',
            'status' => DeliveryOperation::STATUS_PROCESSING,
            'started_at' => now()->subMinutes(31),
        ]);

        $this->artisan('delivery:reconcile-admin-replies', ['--minutes' => 30])
            ->expectsOutput('Reconciled stale client and support mirror deliveries: 1')
            ->assertSuccessful();

        $this->assertSame(DeliveryOperation::STATUS_FAILED, $operation->refresh()->status);
        Queue::assertPushed(NotifyAdminReplyDeliveryFailedJob::class, 1);
    }

    /**
     * @return array{Message, DeliveryOperation}
     */
    private function pendingReply(?int $topicId = null, string $operationName = 'admin-reply'): array
    {
        $botUser = BotUser::create([
            'chat_id' => random_int(100000, 999999),
            'platform' => 'telegram',
            'topic_id' => $topicId,
        ]);
        $message = Message::create([
            'bot_user_id' => $botUser->id,
            'platform' => 'telegram',
            'message_type' => 'outgoing',
            'delivery_status' => Message::DELIVERY_PENDING,
            'from_id' => 0,
            'to_id' => 0,
            'text' => 'Reply',
        ]);
        $operation = DeliveryOperation::create([
            'operation_key' => hash('sha256', 'admin-reply:' . $message->id),
            'bot_user_id' => $botUser->id,
            'message_id' => $message->id,
            'trace_id' => 'admin-message:' . $message->id,
            'destination' => 'telegram-client',
            'operation' => $operationName,
            'status' => DeliveryOperation::STATUS_PENDING,
        ]);

        return [$message, $operation];
    }
}

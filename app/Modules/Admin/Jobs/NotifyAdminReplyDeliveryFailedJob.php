<?php

namespace App\Modules\Admin\Jobs;

use App\Models\BotUser;
use App\Models\DeliveryOperation;
use App\Models\Message;
use App\Modules\Telegram\Api\TelegramMethods;
use App\Services\Settings\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class NotifyAdminReplyDeliveryFailedJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $timeout = 20;

    public array $backoff = [5, 15, 30, 60];

    public function __construct(
        public readonly int $failedOperationId,
        public readonly int $notificationOperationId,
    ) {
        $this->onQueue('telegram-mirror');
    }

    public function handle(): void
    {
        $notification = DeliveryOperation::find($this->notificationOperationId);
        if ($notification === null || $notification->status === DeliveryOperation::STATUS_DELIVERED) {
            return;
        }

        $failedOperation = DeliveryOperation::find($this->failedOperationId);
        $botUser = $failedOperation === null ? null : BotUser::find($failedOperation->bot_user_id);
        $groupId = (string) app(SettingsService::class)->get('telegram.group_id');

        if (
            $failedOperation === null
            || $failedOperation->status !== DeliveryOperation::STATUS_FAILED
            || $botUser === null
            || empty($botUser->topic_id)
            || $groupId === ''
        ) {
            $notification->update([
                'status' => DeliveryOperation::STATUS_FAILED,
                'last_error' => 'Failure is no longer current or existing Telegram topic/group is unavailable',
            ]);

            return;
        }

        $notification->update([
            'status' => DeliveryOperation::STATUS_PROCESSING,
            'attempts' => $notification->attempts + 1,
            'started_at' => now(),
        ]);

        $text = $failedOperation->destination === 'telegram-support-topic'
            ? $this->incomingMirrorFailureText($failedOperation)
            : $this->adminReplyFailureText($failedOperation);
        $response = TelegramMethods::sendQueryTelegram('sendMessage', [
            'chat_id' => $groupId,
            'message_thread_id' => $botUser->topic_id,
            'text' => $text,
        ]);

        if (! $response->ok) {
            throw new RuntimeException(sprintf(
                'Telegram failure notification rejected: code=%s type=%s',
                $response->response_code ?? 0,
                $response->type_error ?? 'UNKNOWN',
            ));
        }

        $notification->update([
            'status' => DeliveryOperation::STATUS_DELIVERED,
            'external_message_id' => $response->message_id,
            'last_error' => null,
            'delivered_at' => now(),
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        DeliveryOperation::whereKey($this->notificationOperationId)->update([
            'status' => DeliveryOperation::STATUS_FAILED,
            'last_error' => mb_substr($exception->getMessage(), 0, 2000),
        ]);

        Log::channel('app')->error('Admin reply failure notification permanently failed', [
            'source' => 'admin_reply_failure_notification_failed',
            'failed_operation_id' => $this->failedOperationId,
            'error_class' => $exception::class,
        ]);
    }

    private function adminReplyFailureText(DeliveryOperation $failedOperation): string
    {
        $reason = trim((string) $failedOperation->last_error);
        $reason = $reason !== '' ? mb_substr($reason, 0, 500) : 'причина не указана';

        return "⚠️ Ответ клиенту не доставлен.\nПричина: {$reason}";
    }

    private function incomingMirrorFailureText(DeliveryOperation $failedOperation): string
    {
        $message = Message::with('attachments')->find($failedOperation->message_id);
        $time = $message?->created_at?->format('d.m.Y H:i') ?? 'неизвестно';
        $text = "⚠️ Входящее сообщение клиента не отображено в теме.\nВремя: {$time}";
        $attachmentTypes = $message?->attachments
            ->pluck('file_type')
            ->unique()
            ->map(fn (string $type): string => match ($type) {
                'photo' => 'фото',
                'voice' => 'голосовое сообщение',
                'sticker' => 'стикер',
                'video_note' => 'видеосообщение',
                default => 'файл',
            })
            ->implode(', ');

        if ($attachmentTypes !== null && $attachmentTypes !== '') {
            $text .= "\nВложение: {$attachmentTypes}";
        }

        return $text;
    }
}

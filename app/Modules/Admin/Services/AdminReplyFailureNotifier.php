<?php

namespace App\Modules\Admin\Services;

use App\Models\BotUser;
use App\Models\DeliveryOperation;
use App\Models\Message;
use App\Modules\Admin\Jobs\NotifyAdminReplyDeliveryFailedJob;

class AdminReplyFailureNotifier
{
    public function queue(DeliveryOperation $failedOperation): bool
    {
        if ($failedOperation->status !== DeliveryOperation::STATUS_FAILED) {
            return false;
        }

        $isAdminReplyFailure = str_ends_with((string) $failedOperation->destination, '-client');
        $isIncomingMirrorFailure = $failedOperation->destination === 'telegram-support-topic'
            && $failedOperation->message_id !== null
            && Message::query()
                ->whereKey($failedOperation->message_id)
                ->where('message_type', 'incoming')
                ->exists();

        if (! $isAdminReplyFailure && ! $isIncomingMirrorFailure) {
            return false;
        }

        $botUser = BotUser::find($failedOperation->bot_user_id);
        if ($botUser === null || empty($botUser->topic_id)) {
            return false;
        }

        $notificationType = $isIncomingMirrorFailure ? 'support-mirror-failure' : 'admin-reply-failure';
        $notification = DeliveryOperation::firstOrCreate(
            ['operation_key' => hash('sha256', "{$notificationType}-notification:" . $failedOperation->operation_key)],
            [
                'bot_user_id' => $failedOperation->bot_user_id,
                'message_id' => $failedOperation->message_id,
                'trace_id' => "{$notificationType}:" . $failedOperation->operation_key,
                'destination' => 'telegram-topic',
                'operation' => "{$notificationType}-notification",
                'status' => DeliveryOperation::STATUS_PENDING,
            ],
        );

        if (! $notification->wasRecentlyCreated) {
            return false;
        }

        NotifyAdminReplyDeliveryFailedJob::dispatch($failedOperation->id, $notification->id);

        return true;
    }
}

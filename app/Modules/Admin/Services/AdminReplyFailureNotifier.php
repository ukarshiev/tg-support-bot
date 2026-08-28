<?php

namespace App\Modules\Admin\Services;

use App\Models\BotUser;
use App\Models\DeliveryOperation;
use App\Modules\Admin\Jobs\NotifyAdminReplyDeliveryFailedJob;

class AdminReplyFailureNotifier
{
    public function queue(DeliveryOperation $failedOperation): bool
    {
        if (
            ! str_ends_with((string) $failedOperation->destination, '-client')
            || $failedOperation->status !== DeliveryOperation::STATUS_FAILED
        ) {
            return false;
        }

        $botUser = BotUser::find($failedOperation->bot_user_id);
        if ($botUser === null || empty($botUser->topic_id)) {
            return false;
        }

        $notification = DeliveryOperation::firstOrCreate(
            ['operation_key' => hash('sha256', 'admin-reply-failure-notification:' . $failedOperation->operation_key)],
            [
                'bot_user_id' => $failedOperation->bot_user_id,
                'message_id' => $failedOperation->message_id,
                'trace_id' => 'admin-reply-failure:' . $failedOperation->operation_key,
                'destination' => 'telegram-topic',
                'operation' => 'admin-reply-failure-notification',
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

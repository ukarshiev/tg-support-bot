<?php

namespace App\Modules\Admin\Services;

use App\Models\DeliveryOperation;
use App\Models\Message;
use Illuminate\Support\Facades\DB;

class AdminReplyDeliveryService
{
    public function __construct(
        private readonly AdminReplyFailureNotifier $failureNotifier,
    ) {
    }

    public function markProcessing(int $operationId): void
    {
        DeliveryOperation::query()
            ->whereKey($operationId)
            ->whereIn('status', [DeliveryOperation::STATUS_PENDING, DeliveryOperation::STATUS_RETRYING])
            ->update([
                'status' => DeliveryOperation::STATUS_PROCESSING,
                'started_at' => now(),
                'attempts' => DB::raw('attempts + 1'),
            ]);
    }

    public function markDelivered(
        DeliveryOperation $operation,
        ?Message $message = null,
        ?int $externalMessageId = null,
    ): void {
        DB::transaction(function () use ($operation, $message, $externalMessageId): void {
            $locked = DeliveryOperation::query()->lockForUpdate()->find($operation->id);
            if ($locked === null || $locked->status === DeliveryOperation::STATUS_DELIVERED) {
                return;
            }

            $messageId = $message->id ?? $locked->message_id;
            $locked->update([
                'message_id' => $messageId,
                'external_message_id' => $externalMessageId ?? $locked->external_message_id,
                'status' => DeliveryOperation::STATUS_DELIVERED,
                'last_error' => null,
                'delivered_at' => now(),
            ]);

            if ($messageId !== null && Message::supportsDeliveryStatus()) {
                Message::whereKey($messageId)->update([
                    'delivery_status' => Message::DELIVERY_DELIVERED,
                ]);
            }
        });
    }

    public function markFailed(DeliveryOperation $operation, string $reason): void
    {
        $failedOperation = DB::transaction(function () use ($operation, $reason): ?DeliveryOperation {
            $locked = DeliveryOperation::query()->lockForUpdate()->find($operation->id);
            if ($locked === null || $locked->status === DeliveryOperation::STATUS_DELIVERED) {
                return null;
            }

            $locked->update([
                'status' => DeliveryOperation::STATUS_FAILED,
                'last_error' => mb_substr($reason, 0, 2000),
            ]);

            if ($locked->message_id !== null && Message::supportsDeliveryStatus()) {
                Message::whereKey($locked->message_id)->update([
                    'delivery_status' => Message::DELIVERY_FAILED,
                ]);
            }

            return $locked->refresh();
        });

        if ($failedOperation !== null) {
            $this->failureNotifier->queue($failedOperation);
        }
    }
}

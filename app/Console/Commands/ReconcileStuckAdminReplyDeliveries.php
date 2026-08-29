<?php

namespace App\Console\Commands;

use App\Models\DeliveryOperation;
use App\Modules\Admin\Services\AdminReplyDeliveryService;
use App\Modules\Admin\Services\AdminReplyFailureNotifier;
use Illuminate\Console\Command;

class ReconcileStuckAdminReplyDeliveries extends Command
{
    protected $signature = 'delivery:reconcile-admin-replies
        {--minutes=15 : Mark stale processing client and support mirror deliveries as failed}';

    protected $description = 'Fail stale client and support mirror deliveries and notify their existing Telegram topics';

    public function handle(
        AdminReplyDeliveryService $deliveryService,
        AdminReplyFailureNotifier $failureNotifier,
    ): int {
        $minutes = (int) $this->option('minutes');
        if ($minutes < 1) {
            $this->error('The --minutes value must be at least 1.');

            return Command::INVALID;
        }

        $cutoff = now()->subMinutes($minutes);
        $count = 0;

        DeliveryOperation::query()
            ->where(function ($query): void {
                $query->where('destination', 'like', '%-client')
                    ->orWhere('destination', 'telegram-support-topic');
            })
            ->where('status', DeliveryOperation::STATUS_PROCESSING)
            ->whereNotNull('started_at')
            ->where('started_at', '<=', $cutoff)
            ->orderBy('id')
            ->chunkById(100, function ($operations) use ($deliveryService, $failureNotifier, $minutes, &$count): void {
                foreach ($operations as $operation) {
                    $reason = "Delivery stayed in processing longer than {$minutes} minutes";
                    if ($operation->destination === 'telegram-support-topic') {
                        $operation->update([
                            'status' => DeliveryOperation::STATUS_FAILED,
                            'last_error' => $reason,
                        ]);
                        $failureNotifier->queue($operation->refresh());
                    } else {
                        $deliveryService->markFailed($operation, $reason);
                    }
                    $count++;
                }
            });

        $this->info("Reconciled stale client and support mirror deliveries: {$count}");

        return Command::SUCCESS;
    }
}

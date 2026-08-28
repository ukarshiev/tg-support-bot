<?php

namespace App\Console\Commands;

use App\Models\DeliveryOperation;
use App\Modules\Admin\Services\AdminReplyDeliveryService;
use Illuminate\Console\Command;

class ReconcileStuckAdminReplyDeliveries extends Command
{
    protected $signature = 'delivery:reconcile-admin-replies
        {--minutes=15 : Mark processing admin replies older than this many minutes as failed}';

    protected $description = 'Fail stale client delivery operations and notify their existing Telegram topics';

    public function handle(AdminReplyDeliveryService $deliveryService): int
    {
        $minutes = (int) $this->option('minutes');
        if ($minutes < 1) {
            $this->error('The --minutes value must be at least 1.');

            return Command::INVALID;
        }

        $cutoff = now()->subMinutes($minutes);
        $count = 0;

        DeliveryOperation::query()
            ->where('destination', 'like', '%-client')
            ->where('status', DeliveryOperation::STATUS_PROCESSING)
            ->whereNotNull('started_at')
            ->where('started_at', '<=', $cutoff)
            ->orderBy('id')
            ->chunkById(100, function ($operations) use ($deliveryService, $minutes, &$count): void {
                foreach ($operations as $operation) {
                    $deliveryService->markFailed(
                        $operation,
                        "Delivery stayed in processing longer than {$minutes} minutes",
                    );
                    $count++;
                }
            });

        $this->info("Reconciled stale admin reply deliveries: {$count}");

        return Command::SUCCESS;
    }
}

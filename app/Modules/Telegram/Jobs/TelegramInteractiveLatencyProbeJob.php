<?php

namespace App\Modules\Telegram\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Redis;

class TelegramInteractiveLatencyProbeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 5;

    public function __construct(
        public readonly string $runId,
        public readonly string $probeId,
        public readonly float $queuedAt,
    ) {
        $this->onQueue('telegram-interactive');
    }

    public function handle(): void
    {
        $redis = Redis::connection('default');
        $key = "telegram:pipeline-probe:{$this->runId}";
        $redis->hset($key, $this->probeId, (int) round((microtime(true) - $this->queuedAt) * 1000));
        $redis->expire($key, 300);
    }
}

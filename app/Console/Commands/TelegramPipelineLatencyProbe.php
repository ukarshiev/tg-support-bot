<?php

namespace App\Console\Commands;

use App\Modules\Telegram\Jobs\TelegramInteractiveLatencyProbeJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class TelegramPipelineLatencyProbe extends Command
{
    protected $signature = 'telegram:pipeline-latency-probe {--samples=20} {--slo=100}';

    protected $description = 'Измеряет ожидание интерактивной Redis-очереди и проверяет p95';

    public function handle(): int
    {
        $samples = max(1, min(200, (int) $this->option('samples')));
        $slo = max(1, (int) $this->option('slo'));
        $runId = (string) Str::uuid();
        $ids = [];

        for ($i = 0; $i < $samples; $i++) {
            $id = (string) Str::uuid();
            $ids[] = $id;
            TelegramInteractiveLatencyProbeJob::dispatch($runId, $id, microtime(true));
        }

        $deadline = microtime(true) + 10;
        $key = "telegram:pipeline-probe:{$runId}";
        $redis = Redis::connection('default');
        $values = [];
        do {
            $values = array_map('intval', $redis->hgetall($key));

            if (count($values) === $samples) {
                break;
            }

            usleep(20_000);
        } while (microtime(true) < $deadline);

        if (count($values) !== $samples) {
            $this->error('Получено проб: ' . count($values) . "/{$samples}");

            return self::FAILURE;
        }

        $redis->del($key);

        sort($values);
        $p95 = $values[max(0, (int) ceil(count($values) * 0.95) - 1)];
        $this->line(sprintf(
            'queue=telegram-interactive samples=%d min=%dms p95=%dms max=%dms slo=%dms',
            $samples,
            $values[0],
            $p95,
            $values[array_key_last($values)],
            $slo,
        ));

        return $p95 < $slo ? self::SUCCESS : self::FAILURE;
    }
}

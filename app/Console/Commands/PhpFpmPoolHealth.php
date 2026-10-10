<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Monitoring\PhpFpmStatusReader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/** Проверяет запас webhook-пула и доступность независимых status-listener обоих пулов. */
class PhpFpmPoolHealth extends Command
{
    protected $signature = 'php-fpm:pool-health';

    protected $description = 'Check PHP-FPM pool status and reserved webhook capacity';

    /** Возвращает 1 и warning с причиной при насыщении webhook или недоступности любого статуса. */
    public function handle(PhpFpmStatusReader $reader): int
    {
        $failed = false;
        foreach (['www', 'webhook'] as $pool) {
            try {
                $status = $reader->read($pool);
                $reasons = [];
                if ($pool === 'webhook' && $status['idle'] < 2) {
                    $reasons[] = 'свободных процессов меньше 2';
                }
                if ($pool === 'webhook' && $status['queue'] > 0) {
                    $reasons[] = 'очередь ожидания больше 0';
                }
                $context = $status;
                $this->line("{$pool}: idle={$status['idle']}, queue={$status['queue']}");
            } catch (RuntimeException $exception) {
                $reasons = ['статус недоступен: ' . $exception->getMessage()];
                $context = ['pool' => $pool];
            }
            if ($reasons !== []) {
                $failed = true;
                $reason = implode('; ', $reasons);
                Log::channel('app')->warning('php_fpm_pool_unhealthy', $context + ['reason' => $reason]);
                $this->error("{$pool}: {$reason}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}

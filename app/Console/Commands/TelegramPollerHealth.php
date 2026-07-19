<?php

namespace App\Console\Commands;

use App\Support\TelegramPollingRuntime;
use Illuminate\Console\Command;

class TelegramPollerHealth extends Command
{
    protected $signature = 'telegram:poller-health
        {channel : Poller channel: main or ai}
        {--max-age=90 : Maximum heartbeat age in seconds}';

    protected $description = 'Check that a Telegram poller recently completed a successful Telegram API cycle';

    public function handle(TelegramPollingRuntime $runtime): int
    {
        $channel = (string) $this->argument('channel');

        if (! in_array($channel, ['main', 'ai'], true)) {
            $this->error('Unknown poller channel. Expected main or ai.');

            return Command::INVALID;
        }

        $maxAge = max(1, (int) $this->option('max-age'));

        if (! $runtime->isHealthy($channel, $maxAge)) {
            $this->error("Telegram {$channel} poller heartbeat is missing or stale.");

            return Command::FAILURE;
        }

        $this->info("Telegram {$channel} poller is healthy.");

        return Command::SUCCESS;
    }
}

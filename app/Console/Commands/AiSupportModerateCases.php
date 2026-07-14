<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Ai\Support\SupportCaseModeratorService;
use Illuminate\Console\Command;

class AiSupportModerateCases extends Command
{
    protected $signature = 'ai:support-moderate
        {--limit=50 : Maximum number of review cases to check}';

    protected $description = 'Moderate AI support RAG candidates with the configured AI moderator.';

    public function handle(SupportCaseModeratorService $service): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $result = $service->moderatePending($limit);

        $this->info('Support moderation complete.');
        $this->line('Checked: ' . $result['checked']);
        $this->line('Updated: ' . $result['updated']);
        $this->line('Failed: ' . $result['failed']);

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}

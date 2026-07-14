<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Ai\Support\SupportCurrentDialogImportService;
use Illuminate\Console\Command;

class AiSupportImportCurrentDialogs extends Command
{
    protected $signature = 'ai:support-import-current
        {--dry-run : Count candidates only, do not write to database}
        {--activate : Save candidates to database}
        {--limit-dialogs= : Limit number of latest dialogs}';

    protected $description = 'Collect current support dialogs into AI support RAG candidates.';

    public function handle(SupportCurrentDialogImportService $service): int
    {
        $activate = (bool) $this->option('activate');
        if (! $activate && ! $this->option('dry-run')) {
            $this->warn('No mode selected, using --dry-run. Add --activate to save data.');
        }

        $limit = $this->option('limit-dialogs');
        $limitDialogs = is_numeric($limit) ? max(1, (int) $limit) : null;

        $result = $service->import($activate, $limitDialogs);

        $this->info($result['dry_run'] ? 'Current dialogs dry-run complete.' : 'Current dialogs import complete.');
        $this->line('Dialogs: ' . $result['dialogs_count']);
        $this->line('Messages: ' . $result['messages_count']);
        $this->line('RAG candidates: ' . $result['chunks_count']);

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Ai\Support\SupportArchiveImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class AiSupportImport extends Command
{
    protected $signature = 'ai:support-import
        {directory : Path to Telegram HTML export directory}
        {--dry-run : Parse only, do not write to database}
        {--activate : Save messages and RAG chunks to database}';

    protected $description = 'Import Telegram support archive into AI support RAG tables.';

    public function handle(SupportArchiveImportService $service): int
    {
        $directory = (string) $this->argument('directory');
        if (! File::isDirectory($directory)) {
            $this->error("Directory not found: {$directory}");

            return self::FAILURE;
        }

        $activate = (bool) $this->option('activate');
        if (! $activate && ! $this->option('dry-run')) {
            $this->warn('No mode selected, using --dry-run. Add --activate to save data.');
        }

        $result = $service->import($directory, $activate);

        $this->info($result['dry_run'] ? 'Support archive dry-run complete.' : 'Support archive import complete.');
        $this->line('Files: ' . implode(', ', $result['files']));
        $this->line('Messages: ' . $result['messages_count']);
        $this->line('RAG chunks: ' . $result['chunks_count']);

        return self::SUCCESS;
    }
}

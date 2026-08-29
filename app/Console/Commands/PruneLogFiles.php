<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Throwable;

class PruneLogFiles extends Command
{
    protected $signature = 'logs:prune
        {--days=7 : Delete log files older than this number of days}
        {--dry-run : Count matching files without deleting them}';

    protected $description = 'Delete stale files directly inside storage/logs';

    public function handle(Filesystem $files): int
    {
        $daysOption = (string) $this->option('days');

        if (! ctype_digit($daysOption) || (int) $daysOption < 1) {
            $this->error('The --days value must be a positive integer.');

            return Command::INVALID;
        }

        $logsPath = storage_path('logs');
        $cutoff = time() - ((int) $daysOption * 86400);
        $dryRun = (bool) $this->option('dry-run');
        $fileCount = 0;
        $byteCount = 0;

        if (! $files->isDirectory($logsPath)) {
            $this->printSummary($dryRun, $fileCount, $byteCount);

            return Command::SUCCESS;
        }

        foreach ($files->files($logsPath) as $file) {
            if ($file->getFilename() === '.gitignore' || $file->isLink()) {
                continue;
            }

            try {
                if ($file->getMTime() >= $cutoff) {
                    continue;
                }

                $size = $file->getSize();

                if (! $dryRun && ! $files->delete($file->getPathname())) {
                    $this->warn("Could not delete {$file->getFilename()}.");

                    continue;
                }

                $fileCount++;
                $byteCount += $size;
            } catch (Throwable $exception) {
                $this->warn("Could not process {$file->getFilename()}: {$exception->getMessage()}");
            }
        }

        $this->printSummary($dryRun, $fileCount, $byteCount);

        return Command::SUCCESS;
    }

    private function printSummary(bool $dryRun, int $fileCount, int $byteCount): void
    {
        if ($dryRun) {
            $this->info("Dry run: files to delete: {$fileCount}; bytes to delete: {$byteCount}.");

            return;
        }

        $this->info("Deleted files: {$fileCount}; deleted bytes: {$byteCount}.");
    }
}

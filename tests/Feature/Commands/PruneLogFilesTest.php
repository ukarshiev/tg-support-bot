<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PruneLogFilesTest extends TestCase
{
    private string $temporaryStoragePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryStoragePath = sys_get_temp_dir() . '/tg-support-log-prune-' . bin2hex(random_bytes(8));
        File::ensureDirectoryExists($this->temporaryStoragePath . '/logs');
        $this->app->useStoragePath($this->temporaryStoragePath);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temporaryStoragePath);

        parent::tearDown();
    }

    public function test_it_deletes_only_stale_files_and_keeps_gitignore(): void
    {
        $oldLog = $this->writeLog('old.log', 'old-log');
        $freshLog = $this->writeLog('fresh.log', 'fresh-log');
        $gitignore = $this->writeLog('.gitignore', "*\n!.gitignore\n");
        File::ensureDirectoryExists(storage_path('logs/archive'));
        $nestedLog = storage_path('logs/archive/nested.log');
        File::put($nestedLog, 'nested-log');

        touch($oldLog, now()->subDays(8)->timestamp);
        touch($gitignore, now()->subDays(8)->timestamp);

        $this->artisan('logs:prune --days=7')
            ->expectsOutput('Deleted files: 1; deleted bytes: 7.')
            ->assertSuccessful();

        $this->assertFileDoesNotExist($oldLog);
        $this->assertFileExists($freshLog);
        $this->assertFileExists($gitignore);
        $this->assertFileExists($nestedLog);
    }

    public function test_dry_run_counts_files_and_bytes_without_deleting(): void
    {
        $firstLog = $this->writeLog('first.log', '12345');
        $secondLog = $this->writeLog('second.log', '1234567');
        touch($firstLog, now()->subDays(8)->timestamp);
        touch($secondLog, now()->subDays(9)->timestamp);

        $this->artisan('logs:prune --days=7 --dry-run')
            ->expectsOutput('Dry run: files to delete: 2; bytes to delete: 12.')
            ->assertSuccessful();

        $this->assertFileExists($firstLog);
        $this->assertFileExists($secondLog);
    }

    private function writeLog(string $name, string $contents): string
    {
        $path = storage_path('logs/' . $name);
        File::put($path, $contents);

        return $path;
    }
}

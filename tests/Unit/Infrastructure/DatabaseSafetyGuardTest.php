<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\DatabaseSafetyGuard;

final class DatabaseSafetyGuardTest extends TestCase
{
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function test_missing_cache_is_safe(): void
    {
        DatabaseSafetyGuard::assertCachedConfigIsSafe(sys_get_temp_dir() . '/missing-' . bin2hex(random_bytes(8)) . '.php');

        $this->addToAssertionCount(1);
    }

    public function test_sqlite_memory_cache_is_safe(): void
    {
        $path = $this->writeConfig('sqlite', ':memory:');

        DatabaseSafetyGuard::assertCachedConfigIsSafe($path);

        $this->addToAssertionCount(1);
    }

    public function test_postgres_cache_is_rejected(): void
    {
        $path = $this->writeConfig('pgsql', 'tg_support_bot');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PHPUnit refused to start');

        DatabaseSafetyGuard::assertCachedConfigIsSafe($path);
    }

    public function test_file_backed_sqlite_cache_is_rejected(): void
    {
        $path = $this->writeConfig('sqlite', '/tmp/testing.sqlite');

        $this->expectException(RuntimeException::class);

        DatabaseSafetyGuard::assertCachedConfigIsSafe($path);
    }

    private function writeConfig(string $connection, string $database): string
    {
        $path = tempnam(sys_get_temp_dir(), 'db-safety-');

        self::assertIsString($path);

        $content = '<?php return ' . var_export([
            'database' => [
                'default' => $connection,
                'connections' => [
                    $connection => ['database' => $database],
                ],
            ],
        ], true) . ';';

        file_put_contents($path, $content);
        $this->temporaryFiles[] = $path;

        return $path;
    }
}

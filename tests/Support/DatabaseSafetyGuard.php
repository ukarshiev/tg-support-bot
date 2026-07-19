<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

final class DatabaseSafetyGuard
{
    public static function assertCachedConfigIsSafe(string $path): void
    {
        if (! is_file($path)) {
            return;
        }

        $config = (static fn (string $configPath): mixed => require $configPath)($path);

        if (! is_array($config)) {
            throw new RuntimeException("PHPUnit refused to start: Laravel config cache is invalid: {$path}");
        }

        $connection = $config['database']['default'] ?? null;
        $database = is_string($connection)
            ? ($config['database']['connections'][$connection]['database'] ?? null)
            : null;

        if ($connection !== 'sqlite' || $database !== ':memory:') {
            $connectionLabel = is_scalar($connection) ? (string) $connection : 'unknown';
            $databaseLabel = is_scalar($database) ? (string) $database : 'unknown';

            throw new RuntimeException(
                'PHPUnit refused to start because Laravel cached a non-isolated database '
                . "({$connectionLabel}:{$databaseLabel}) in {$path}. "
                . 'Use scripts/run-isolated-tests.ps1; never run PHPUnit through the production Compose app service.'
            );
        }
    }
}

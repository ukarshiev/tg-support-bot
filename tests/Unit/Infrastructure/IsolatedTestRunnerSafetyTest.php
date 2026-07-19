<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use PHPUnit\Framework\TestCase;

final class IsolatedTestRunnerSafetyTest extends TestCase
{
    public function test_runner_cannot_reach_production_database(): void
    {
        $script = file_get_contents(dirname(__DIR__, 3) . '/scripts/run-isolated-tests.ps1');

        $this->assertIsString($script);
        $this->assertStringContainsString("'--network', 'none'", $script);
        $this->assertStringContainsString("'--read-only'", $script);
        $this->assertStringContainsString('target=/work,readonly', $script);
        $this->assertStringContainsString("'DB_CONNECTION=sqlite'", $script);
        $this->assertStringContainsString("'DB_DATABASE=:memory:'", $script);
        $this->assertStringNotContainsString('docker compose run', $script);
    }
}

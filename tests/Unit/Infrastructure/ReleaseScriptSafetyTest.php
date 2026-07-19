<?php

namespace Tests\Unit\Infrastructure;

use PHPUnit\Framework\TestCase;

class ReleaseScriptSafetyTest extends TestCase
{
    public function test_release_preserves_runnable_rollback_images(): void
    {
        $script = file_get_contents(dirname(__DIR__, 3) . '/start.sh');

        $this->assertIsString($script);
        $this->assertStringContainsString('PREVIOUS_IMAGE_TAGS', $script);
        $this->assertStringContainsString('tg-support-bot-rollback-${service}:previous', $script);
        $this->assertStringContainsString('docker image tag "${PREVIOUS_IMAGE_TAGS[$service]}"', $script);
    }

    public function test_release_clears_stale_package_manifest_before_artisan_boot(): void
    {
        $script = file_get_contents(dirname(__DIR__, 3) . '/start.sh');

        $this->assertIsString($script);
        $clearPosition = strpos($script, 'rm -f bootstrap/cache/*.php');
        $migratePosition = strpos($script, 'php artisan migrate --force');

        $this->assertNotFalse($clearPosition);
        $this->assertNotFalse($migratePosition);
        $this->assertLessThan($migratePosition, $clearPosition);
    }

    public function test_windows_release_requires_explicit_confirmation_and_backup_before_migrations(): void
    {
        $script = file_get_contents(dirname(__DIR__, 3) . '/start-relaxaclub-windows-docker.ps1');

        $this->assertIsString($script);
        $this->assertStringContainsString('[switch]$ApplyMigrations', $script);
        $this->assertStringContainsString('[switch]$ConfirmProductionChange', $script);
        $this->assertStringContainsString('pg_dump', $script);
        $this->assertStringContainsString('Skip database migrations', $script);
        $this->assertLessThan(strpos($script, 'php artisan migrate --force'), strpos($script, 'pg_dump'));
    }
}

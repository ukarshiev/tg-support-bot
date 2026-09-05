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

    public function test_release_pauses_ingress_and_workers_around_migration(): void
    {
        $script = file_get_contents(dirname(__DIR__, 3) . '/start.sh');

        $this->assertIsString($script);
        $backupPosition = strpos($script, 'pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB"');
        $rememberImagesPosition = strpos($script, 'for service in "${SERVICES[@]}";', $backupPosition);
        $buildPosition = strpos($script, 'docker compose build --pull', $backupPosition);
        $stopPosition = strpos(
            $script,
            'docker compose stop telegram_poller ai_telegram_poller queue scheduler',
            $backupPosition,
        );
        $appPosition = strpos($script, 'docker compose up -d pgdb redis app', $backupPosition);
        $migratePosition = strpos($script, 'php artisan migrate --force', $backupPosition);
        $queuePosition = strpos($script, 'docker compose up -d queue reverb scheduler', $backupPosition);

        $this->assertNotFalse($backupPosition);
        $this->assertNotFalse($rememberImagesPosition);
        $this->assertNotFalse($buildPosition);
        $this->assertNotFalse($stopPosition);
        $this->assertNotFalse($appPosition);
        $this->assertNotFalse($migratePosition);
        $this->assertNotFalse($queuePosition);
        $this->assertLessThan($rememberImagesPosition, $backupPosition);
        $this->assertLessThan($buildPosition, $rememberImagesPosition);
        $this->assertLessThan($stopPosition, $buildPosition);
        $this->assertLessThan($appPosition, $stopPosition);
        $this->assertLessThan($migratePosition, $stopPosition);
        $this->assertLessThan($queuePosition, $migratePosition);
        $this->assertStringContainsString('Telegram keeps pending updates for 24 hours', $script);
    }

    public function test_release_limits_migration_lock_and_statement_waits(): void
    {
        $script = file_get_contents(dirname(__DIR__, 3) . '/start.sh');

        $this->assertIsString($script);
        $timeoutPosition = strpos(
            $script,
            "PGOPTIONS='-c lock_timeout=15s -c statement_timeout=10min'",
        );
        $migratePosition = strpos($script, 'app php artisan migrate --force');

        $this->assertNotFalse($timeoutPosition);
        $this->assertNotFalse($migratePosition);
        $this->assertLessThan($migratePosition, $timeoutPosition);
    }

    public function test_release_starts_pollers_only_after_core_readiness_checks(): void
    {
        $script = file_get_contents(dirname(__DIR__, 3) . '/start.sh');

        $this->assertIsString($script);
        $readinessPosition = strpos($script, 'release_ready=false');
        $aboutPosition = strpos($script, 'php artisan about --only=environment', $readinessPosition);
        $horizonPosition = strpos($script, 'php artisan horizon:status', $readinessPosition);
        $readinessGuardPosition = strpos($script, 'if [[ "$release_ready" != true ]]', $readinessPosition);
        $pollerStartPosition = strpos(
            $script,
            'docker compose up -d telegram_poller ai_telegram_poller',
            $readinessPosition,
        );
        $pollerHealthPosition = strpos($script, 'if pollers_ready; then', $pollerStartPosition);

        $this->assertNotFalse($readinessPosition);
        $this->assertNotFalse($aboutPosition);
        $this->assertNotFalse($horizonPosition);
        $this->assertNotFalse($readinessGuardPosition);
        $this->assertNotFalse($pollerStartPosition);
        $this->assertNotFalse($pollerHealthPosition);
        $this->assertLessThan($pollerStartPosition, $aboutPosition);
        $this->assertLessThan($pollerStartPosition, $horizonPosition);
        $this->assertLessThan($pollerStartPosition, $readinessGuardPosition);
        $this->assertLessThan($pollerHealthPosition, $pollerStartPosition);
        $this->assertStringContainsString(
            'readonly POLLER_HEALTH_SERVICES=(telegram_poller ai_telegram_poller)',
            $script,
        );
    }

    public function test_release_allows_enough_time_for_poller_healthchecks(): void
    {
        $script = file_get_contents(dirname(__DIR__, 3) . '/start.sh');

        $this->assertIsString($script);
        $this->assertSame(
            1,
            preg_match('/readonly HEALTHCHECK_INTERVAL_SECONDS=(\d+)/', $script, $interval),
        );
        $this->assertSame(
            1,
            preg_match('/readonly REQUIRED_HEALTHCHECK_INTERVALS=(\d+)/', $script, $checks),
        );
        $this->assertSame(
            1,
            preg_match('/readonly HEALTHCHECK_WAIT_SAFETY_MARGIN_SECONDS=(\d+)/', $script, $margin),
        );
        $this->assertSame(
            1,
            preg_match('/readonly POLLER_HEALTHCHECK_START_PERIOD_SECONDS=(\d+)/', $script, $startPeriod),
        );

        $pollerTimeout = (int) $startPeriod[1]
            + (int) $checks[1] * (int) $interval[1]
            + (int) $margin[1];

        $this->assertGreaterThanOrEqual(150, $pollerTimeout);
        $this->assertStringContainsString(
            'readonly POLLER_READY_TIMEOUT_SECONDS=$((POLLER_HEALTHCHECK_START_PERIOD_SECONDS + REQUIRED_HEALTHCHECK_INTERVALS * HEALTHCHECK_INTERVAL_SECONDS + HEALTHCHECK_WAIT_SAFETY_MARGIN_SECONDS))',
            $script,
        );
        $this->assertStringContainsString(
            'Telegram pollers did not become healthy in ${POLLER_READY_TIMEOUT_SECONDS} seconds.',
            $script,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/Telegram pollers did not become healthy in \d+ seconds\./',
            $script,
        );
    }

    public function test_rollback_restarts_every_service_paused_for_release(): void
    {
        $script = file_get_contents(dirname(__DIR__, 3) . '/start.sh');

        $this->assertIsString($script);
        $rollbackStart = strpos($script, 'rollback()');
        $rollbackEnd = strpos($script, 'trap rollback ERR');
        $this->assertNotFalse($rollbackStart);
        $this->assertNotFalse($rollbackEnd);

        $rollback = substr($script, $rollbackStart, $rollbackEnd - $rollbackStart);
        $this->assertStringContainsString('RELEASE_PAUSE_STARTED', $rollback);
        $this->assertStringContainsString('telegram_poller', $rollback);
        $this->assertStringContainsString('ai_telegram_poller', $rollback);
        $this->assertStringContainsString('queue', $rollback);
        $this->assertStringContainsString('scheduler', $rollback);
    }

    public function test_rollback_reports_missing_image_and_failed_health_recovery(): void
    {
        $script = file_get_contents(dirname(__DIR__, 3) . '/start.sh');

        $this->assertIsString($script);
        $rollbackStart = strpos($script, 'rollback()');
        $rollbackEnd = strpos($script, 'trap rollback ERR');
        $rollback = substr($script, $rollbackStart, $rollbackEnd - $rollbackStart);

        $this->assertStringContainsString('docker image inspect "${PREVIOUS_IMAGE_TAGS[$service]}"', $rollback);
        $this->assertStringContainsString("previous image for service '\${service}' is missing", $rollback);
        $this->assertStringContainsString('if services_ready; then', $rollback);
        $this->assertStringContainsString('if pollers_ready; then', $rollback);
        $this->assertStringContainsString('AUTOMATIC ROLLBACK FAILED. Production may be unavailable; manual intervention is required.', $rollback);
        $this->assertStringContainsString('exit 2', $rollback);
    }

    public function test_release_uses_current_compose_services_and_renders_nginx_config(): void
    {
        $script = file_get_contents(dirname(__DIR__, 3) . '/start.sh');

        $this->assertIsString($script);
        $this->assertStringNotContainsString('docker compose up --no-deps assets_init', $script);
        $this->assertStringContainsString('docker/nginx/default.conf.template', $script);
        $this->assertStringContainsString('docker/nginx/default.windows-docker.conf.template', $script);
        $this->assertStringContainsString('/etc/letsencrypt/live/${main_domain}/fullchain.pem', $script);
        $this->assertStringContainsString('s/__MAIN_DOMAIN__/${main_domain}/g', $script);
        $this->assertStringContainsString('cp "$PREVIOUS_NGINX_CONFIG" docker/nginx/default.conf', $script);
        $this->assertStringContainsString('docker compose up -d --force-recreate nginx', $script);
        $this->assertStringContainsString('services_ready', $script);
        $this->assertStringContainsString('HEALTH_SERVICES', $script);
        $this->assertLessThan(
            strpos($script, 'docker compose up -d pgdb redis app'),
            strpos($script, 'mv "${nginx_config}.tmp" "$nginx_config"'),
        );
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

    public function test_windows_release_creates_nginx_config_and_uses_baked_dependencies(): void
    {
        $script = file_get_contents(dirname(__DIR__, 3) . '/start-relaxaclub-windows-docker.ps1');

        $this->assertIsString($script);
        $this->assertStringContainsString(
            '$nginxOutputPath = Join-Path $nginxDirectory "default.conf"',
            $script,
        );
        $this->assertStringContainsString(
            '[System.IO.File]::WriteAllText($nginxOutputPath',
            $script,
        );
        $this->assertStringNotContainsString('Resolve-Path "docker/nginx/default.conf"', $script);
        $this->assertStringNotContainsString('composer install', $script);
        $this->assertStringContainsString('test -f vendor/autoload.php', $script);
        $this->assertStringContainsString(
            'docker compose restart app nginx queue reverb scheduler telegram_poller ai_telegram_poller',
            $script,
        );
    }
}

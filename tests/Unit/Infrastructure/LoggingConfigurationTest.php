<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use PHPUnit\Framework\TestCase;

class LoggingConfigurationTest extends TestCase
{
    public function test_rotating_channels_have_safe_defaults(): void
    {
        $configuration = file_get_contents(dirname(__DIR__, 3) . '/config/logging.php');

        $this->assertIsString($configuration);
        $this->assertStringContainsString("env('LOG_STACK', 'daily')", $configuration);
        $this->assertSame(2, substr_count($configuration, "env('LOG_DAILY_DAYS', 7)"));
        $this->assertStringNotContainsString("env('LOG_DAILY_DAYS', 14)", $configuration);
    }

    public function test_log_pruning_is_scheduled_daily_without_overlap(): void
    {
        $schedule = file_get_contents(dirname(__DIR__, 3) . '/routes/console.php');

        $this->assertIsString($schedule);
        $this->assertMatchesRegularExpression(
            "/Schedule::command\\('logs:prune --days=7'\\)\\s*->dailyAt\\('03:30'\\)\\s*->withoutOverlapping\\(\\)/",
            $schedule,
        );
    }
}

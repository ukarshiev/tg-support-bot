<?php

namespace Tests\Unit\Infrastructure;

use PHPUnit\Framework\TestCase;

class TelegramSupportFlowScheduleTest extends TestCase
{
    public function test_support_flow_check_runs_once_per_day(): void
    {
        $schedule = file_get_contents(dirname(__DIR__, 3) . '/routes/console.php');

        $this->assertIsString($schedule);
        $this->assertMatchesRegularExpression(
            "/Schedule::command\\('telegram:support-flow-check'\\)\\s*->daily\\(\\)/",
            $schedule,
        );
        $this->assertStringNotContainsString('->everyThreeHours()', $schedule);
    }
}

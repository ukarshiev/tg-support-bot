<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/** Проверяет резерв webhook-пула, закрытый статус и оба шаблона nginx. */
class PhpFpmPoolConfigurationTest extends TestCase
{
    /** Пулы сохраняют предел 20 и отдельные глубокие файловые slowlog. */
    public function test_pool_capacity_timeouts_and_private_status_are_configured(): void
    {
        $contents = $this->read('docker/php-fpm/zz-relaxa-pool.conf');
        $pools = parse_ini_string($contents, true, INI_SCANNER_RAW);
        $this->assertIsArray($pools);
        $this->assertSame(['www', 'webhook'], array_keys($pools));
        $this->assertSame('dynamic', $pools['www']['pm']);
        $this->assertSame('14', $pools['www']['pm.max_children']);
        $this->assertSame('static', $pools['webhook']['pm']);
        $this->assertSame('6', $pools['webhook']['pm.max_children']);
        $this->assertLessThanOrEqual(20, array_sum(array_column($pools, 'pm.max_children')));
        $this->assertSame('9001', $pools['webhook']['listen']);
        $this->assertSame('www-data', $pools['webhook']['user']);
        $this->assertSame('www-data', $pools['webhook']['group']);
        $this->assertSame('no', $pools['webhook']['clear_env']);
        $this->assertSame('120s', $pools['www']['request_terminate_timeout']);
        $this->assertSame('30s', $pools['webhook']['request_terminate_timeout']);
        foreach (['www' => 9101, 'webhook' => 9102] as $pool => $port) {
            $this->assertSame("0.0.0.0:{$port}", $pools[$pool]['pm.status_listen']);
            $this->assertSame('/fpm-status', $pools[$pool]['pm.status_path']);
            $this->assertSame('5s', $pools[$pool]['request_slowlog_timeout']);
            $this->assertSame('60', $pools[$pool]['request_slowlog_trace_depth']);
            $this->assertSame("/var/www/storage/logs/php-fpm/php-fpm-{$pool}.slow.log", $pools[$pool]['slowlog']);
            $this->assertSame('yes', $pools[$pool]['catch_workers_output']);
        }
        $this->assertStringNotContainsString('/proc/self/fd/2', $contents);
    }

    /** Точные webhook-пути копируют существующие параметры с отдельным upstream и index.php. */
    public function test_both_templates_route_webhooks_to_reserved_pool(): void
    {
        foreach (['default.conf.template', 'default.windows-docker.conf.template'] as $template) {
            $contents = $this->read('docker/nginx/' . $template);
            $this->assertMatchesRegularExpression('/upstream app_webhook\s*\{\s*zone app_webhook 64k;\s*server app:9001 resolve;\s*\}/', $contents);
            preg_match('/location = \/index\.php\s*\{([^}]+)\}/', $contents, $original);
            $this->assertArrayHasKey(1, $original);
            $this->assertStringContainsString('fastcgi_pass app_backend;', $original[1]);
            foreach (['/api/telegram/bot', '/api/ai-bot/webhook'] as $path) {
                preg_match('/location = ' . preg_quote($path, '/') . '\s*\{([^}]+)\}/', $contents, $location);
                $this->assertArrayHasKey(1, $location, $template . ' ' . $path);
                $expected = str_replace(
                    ['fastcgi_pass app_backend;', 'SCRIPT_FILENAME /var/www/public$fastcgi_script_name;'],
                    ['fastcgi_pass app_webhook;', 'SCRIPT_FILENAME /var/www/public/index.php;'],
                    $original[1],
                );
                $this->assertSame($expected, $location[1]);
            }
            $this->assertSame(2, substr_count($contents, 'fastcgi_pass app_webhook;'));
            $this->assertStringNotContainsString('9101', $contents);
            $this->assertStringNotContainsString('9102', $contents);
        }
    }

    /** Scheduler сохраняет собственную сеть; status-порты не публикуются, slowlog в подкаталоге. */
    public function test_scheduler_preserves_network_and_startup_creates_slowlog_subdirectory(): void
    {
        $compose = Yaml::parse($this->read('docker-compose.yml'));
        $scheduler = $compose['services']['scheduler'];
        $this->assertArrayNotHasKey('network_mode', $scheduler);
        $this->assertSame(['pet'], $scheduler['networks']);
        $this->assertSame(['8.8.8.8', '1.1.1.1'], $scheduler['dns']);
        $this->assertSame(['host.docker.internal:host-gateway'], $scheduler['extra_hosts']);
        $this->assertArrayNotHasKey('ports', $scheduler);
        $this->assertArrayNotHasKey('ports', $compose['services']['app']);
        $command = $compose['services']['app']['command'];
        $this->assertStringContainsString('mkdir -p /var/www/storage/logs/php-fpm &&', $command);
        $this->assertStringNotContainsString('test -w', $command);
        $this->assertSame('33:33', $compose['services']['app']['user']);
        $this->assertMatchesRegularExpression(
            "/Schedule::command\\('php-fpm:pool-health'\\)->everyMinute\\(\\)->withoutOverlapping\\(\\)/",
            $this->read('routes/console.php'),
        );
    }

    private function read(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 3) . '/' . $path);
        $this->assertIsString($contents);

        return $contents;
    }
}

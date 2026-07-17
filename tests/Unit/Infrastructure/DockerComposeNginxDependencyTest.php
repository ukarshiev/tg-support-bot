<?php

namespace Tests\Unit\Infrastructure;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class DockerComposeNginxDependencyTest extends TestCase
{
    /**
     * Проверяет регрессию, из-за которой после пересоздания app nginx стартовал
     * раньше PHP-FPM и отдавал 502 Bad Gateway.
     */
    public function test_nginx_waits_for_healthy_app_before_starting(): void
    {
        $compose = Yaml::parseFile(dirname(__DIR__, 3) . '/docker-compose.yml');

        $this->assertSame(
            'service_healthy',
            $compose['services']['nginx']['depends_on']['app']['condition'] ?? null,
            'nginx обязан ждать healthy app, иначе Docker DNS может не найти upstream app:9000.',
        );

        $this->assertNotEmpty(
            $compose['services']['app']['healthcheck']['test'] ?? null,
            'У app должен быть healthcheck, на который nginx может опереться.',
        );
    }

    public function test_internal_pollers_wait_for_nginx_and_queue_health(): void
    {
        $compose = Yaml::parseFile(dirname(__DIR__, 3) . '/docker-compose.yml');

        foreach (['telegram_poller', 'ai_telegram_poller'] as $service) {
            $this->assertSame('service_healthy', $compose['services'][$service]['depends_on']['app']['condition'] ?? null);
            $this->assertSame('service_healthy', $compose['services'][$service]['depends_on']['nginx']['condition'] ?? null);
            $this->assertSame('service_healthy', $compose['services'][$service]['depends_on']['queue']['condition'] ?? null);
        }
    }

    public function test_nginx_re_resolves_recreated_app_and_checks_real_php_health(): void
    {
        $root = dirname(__DIR__, 3);
        $compose = Yaml::parseFile($root . '/docker-compose.yml');
        $nginxTemplate = file_get_contents($root . '/docker/nginx/default.conf.template');

        $this->assertIsString($nginxTemplate);
        $this->assertStringContainsString('resolver 127.0.0.11', $nginxTemplate);
        $this->assertStringContainsString('server app:9000 resolve;', $nginxTemplate);
        $this->assertStringContainsString('fastcgi_pass app_backend;', $nginxTemplate);

        $healthcheck = implode(' ', $compose['services']['nginx']['healthcheck']['test'] ?? []);
        $this->assertStringContainsString('/up', $healthcheck);
        $this->assertStringContainsString('curl', $healthcheck);
    }

    public function test_signed_file_requests_remain_excluded_after_internal_redirect(): void
    {
        $root = dirname(__DIR__, 3);

        foreach (['default.conf.template', 'default.windows-docker.conf.template'] as $template) {
            $nginx = file_get_contents($root . '/docker/nginx/' . $template);

            $this->assertIsString($nginx);
            $this->assertStringContainsString('map $request_uri $file_proxy_loggable', $nginx);
            $this->assertStringContainsString('~^/api/files/ 0;', $nginx);
            $this->assertStringContainsString('access_log /var/log/nginx/access.log combined if=$file_proxy_loggable;', $nginx);
        }
    }
}

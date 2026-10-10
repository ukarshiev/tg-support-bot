<?php

namespace Tests\Unit\Infrastructure;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

/**
 * Проверяет замену публичных DNS роутерами в Compose-надстройке Ubuntu-ВМ.
 */
class ProxmoxComposeDnsTest extends TestCase
{
    /**
     * DNS заменяются целиком, а сети и штатная остановка очереди сохраняются.
     */
    public function test_vm_services_override_dns_with_home_routers_and_preserve_runtime_settings(): void
    {
        $path = dirname(__DIR__, 3) . '/docker-compose.proxmox.yml';
        $compose = Yaml::parseFile($path, Yaml::PARSE_CUSTOM_TAGS);

        foreach (['app', 'queue', 'scheduler', 'telegram_poller', 'ai_telegram_poller'] as $service) {
            $dns = $compose['services'][$service]['dns'] ?? null;

            $this->assertInstanceOf(TaggedValue::class, $dns, $service);
            $this->assertSame('override', $dns->getTag(), $service);
            $this->assertSame(['192.168.1.1', '192.168.0.1'], $dns->getValue(), $service);
            $this->assertSame(['pet', 'telegram_egress'], $compose['services'][$service]['networks'] ?? null, $service);
        }

        $this->assertSame('SIGTERM', $compose['services']['queue']['stop_signal'] ?? null);
        $this->assertSame('45s', $compose['services']['queue']['stop_grace_period'] ?? null);

        $contents = file_get_contents($path);

        $this->assertIsString($contents);
        $this->assertStringNotContainsString('8.8.8.8', $contents);
        $this->assertStringNotContainsString('1.1.1.1', $contents);
    }
}

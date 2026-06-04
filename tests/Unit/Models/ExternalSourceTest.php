<?php

namespace Tests\Unit\Models;

use App\Models\ExternalSource;
use PHPUnit\Framework\TestCase;

class ExternalSourceTest extends TestCase
{
    public function test_ip_allowed_when_allowlist_empty(): void
    {
        $source = new ExternalSource();
        $source->allowed_ips = null;

        $this->assertTrue($source->isIpAllowed('203.0.113.10'));
        $this->assertTrue($source->isIpAllowed(null));
    }

    public function test_ip_allowed_when_in_list(): void
    {
        $source = new ExternalSource();
        $source->allowed_ips = ['203.0.113.10', '198.51.100.5'];

        $this->assertTrue($source->isIpAllowed('198.51.100.5'));
    }

    public function test_ip_rejected_when_not_in_list(): void
    {
        $source = new ExternalSource();
        $source->allowed_ips = ['203.0.113.10'];

        $this->assertFalse($source->isIpAllowed('5.6.7.8'));
        $this->assertFalse($source->isIpAllowed(null));
    }
}

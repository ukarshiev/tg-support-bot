<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Translation;

use App\Modules\Translation\Support\PlaceholderProtector;
use PHPUnit\Framework\TestCase;

class PlaceholderProtectorTest extends TestCase
{
    public function test_it_protects_placeholders_links_and_mentions(): void
    {
        $protector = new PlaceholderProtector();

        [$protected, $map] = $protector->protect('Привет {name}, ссылка https://example.com и @support_bot');

        $this->assertStringNotContainsString('__TG_SUPPORT_PH_', $protected);
        $this->assertStringContainsString('<x id="tgph0">', $protected);
        $this->assertCount(3, $map);
        $this->assertSame('Привет {name}, ссылка https://example.com и @support_bot', $protector->restore($protected, $map));
    }

    public function test_it_protects_mustache_variables_without_technical_tokens(): void
    {
        $protector = new PlaceholderProtector();

        [$protected, $map] = $protector->protect('Open {{connector}} and {{paybot}}.');

        $this->assertStringContainsString('<x id="tgph0">{{connector}}</x>', $protected);
        $this->assertStringContainsString('<x id="tgph1">{{paybot}}</x>', $protected);
        $this->assertStringNotContainsString('__TG_SUPPORT_PH_', $protected);
        $this->assertSame(
            'Translated {{connector}} and {{paybot}}.',
            $protector->restore('Translated <x id="tgph0">{{connector}}</x> and <x id="tgph1">{{paybot}}</x>.', $map)
        );
    }
}

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

        $this->assertMatchesRegularExpression('/__TGSPH_[A-F0-9]{12}_0000__/', $protected);
        $this->assertStringNotContainsString('{name}', $protected);
        $this->assertStringNotContainsString('https://example.com', $protected);
        $this->assertStringNotContainsString('@support_bot', $protected);
        $this->assertCount(3, $map);
        $this->assertSame('Привет {name}, ссылка https://example.com и @support_bot', $protector->restoreSafely($protected, $map));
    }

    public function test_it_hides_mustache_variables_from_provider_and_restores_them(): void
    {
        $protector = new PlaceholderProtector();

        [$protected, $map] = $protector->protect('Open {{connector}} and {{paybot}}.');

        $this->assertStringNotContainsString('{{connector}}', $protected);
        $this->assertStringNotContainsString('{{paybot}}', $protected);
        $this->assertMatchesRegularExpression('/__TGSPH_[A-F0-9]{12}_0000__/', $protected);
        $this->assertMatchesRegularExpression('/__TGSPH_[A-F0-9]{12}_0001__/', $protected);
        $markers = array_keys($map);
        $this->assertSame(
            'Translated {{connector}} and {{paybot}}.',
            $protector->restoreSafely("Translated {$markers[0]} and {$markers[1]}.", $map)
        );
    }

    public function test_it_rejects_provider_response_when_marker_is_missing_or_duplicated(): void
    {
        $protector = new PlaceholderProtector();
        [$protected, $map] = $protector->protect('Open {{connector}}.');
        $marker = array_key_first($map);

        $this->assertNotNull($marker);
        $this->assertNull($protector->restoreSafely('Translated without marker.', $map));
        $this->assertNull($protector->restoreSafely("Translated {$marker}{$marker}.", $map));
        $this->assertSame('Open {{connector}}.', $protector->restoreSafely($protected, $map));
        $this->assertNull($protector->restoreSafely($protected . ' __TGSPH_ABCDEF123456_9999__', $map));
    }

    public function test_it_rejects_legacy_yandex_corruption_and_broken_xml(): void
    {
        $protector = new PlaceholderProtector();
        $source = 'Коннектор — {{connector}}. Бот — {{paybot}}.';

        $this->assertFalse($protector->isValidTranslation(
            $source,
            'موصل - < س معرف= "تغف 0" > {{موصل}}< / س> بوت {{بايبوت}}',
        ));
        $this->assertFalse($protector->isValidTranslation(
            $source,
            'Connector - <x id="tgph0">{{connector}}</x>. Bot - {{paybot}}.',
        ));
        $this->assertTrue($protector->isValidTranslation(
            $source,
            'Connector - {{connector}}. Bot - {{paybot}}.',
        ));
    }
}

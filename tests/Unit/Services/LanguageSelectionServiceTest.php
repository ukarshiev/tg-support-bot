<?php

namespace Tests\Unit\Services;

use App\Modules\Telegram\Services\SupportLanguageService;
use App\Services\LanguageSelectionService;
use Tests\TestCase;

class LanguageSelectionServiceTest extends TestCase
{
    public function test_it_recognizes_cross_platform_language_menu_commands(): void
    {
        $service = app(LanguageSelectionService::class);

        $this->assertTrue($service->isMenuCommand('/start'));
        $this->assertTrue($service->isMenuCommand(' /LANGUAGE '));
        $this->assertFalse($service->isMenuCommand('hello'));
    }

    public function test_it_extracts_callbacks_from_vk_and_max_payloads(): void
    {
        $service = app(LanguageSelectionService::class);

        $this->assertSame('select_language:en', $service->callbackData('{"command":"select_language:en"}'));
        $this->assertSame('select_language:de', $service->callbackData('select_language:de'));
        $this->assertSame('select_language:fr', $service->callbackData(['command' => 'select_language:fr']));
    }

    public function test_selector_uses_english_before_choice_and_localized_title_after_choice(): void
    {
        $languages = app(SupportLanguageService::class);

        $this->assertSame('Choose language', $languages->prompt());
        $this->assertSame('Выберите язык', $languages->prompt(locale: 'ru'));
    }

    public function test_navigation_is_neutral(): void
    {
        $keyboard = app(SupportLanguageService::class)->keyboard(1);
        $navigation = $keyboard[array_key_last($keyboard)];

        $this->assertSame(['1/2', '▶'], array_column($navigation, 'text'));
    }
}

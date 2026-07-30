<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\BotUser;
use App\Services\ClientLanguageService;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ClientLanguageServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsService::class)->set('support.languages', [
            'ru' => ['code' => 'ru', 'name' => 'Русский', 'native' => 'Русский', 'enabled' => true, 'show_on_start' => true, 'sort_order' => 1],
            'tr' => ['code' => 'tr', 'name' => 'Türkçe', 'native' => 'Türkçe', 'enabled' => true, 'show_on_start' => true, 'sort_order' => 2],
            'de' => ['code' => 'de', 'name' => 'Deutsch', 'native' => 'Deutsch', 'enabled' => false, 'show_on_start' => false, 'sort_order' => 3],
        ]);
    }

    public function test_client_and_admin_use_the_same_language_fields(): void
    {
        $user = BotUser::create(['chat_id' => 9001, 'platform' => 'telegram']);

        $selected = app(ClientLanguageService::class)->select($user, 'TR');

        $this->assertSame('tr', $selected->preferred_language_code);
        $this->assertSame('Türkçe', $selected->preferred_language_name);
        $this->assertNotNull($selected->preferred_language_selected_at);
        $this->assertTrue(app(ClientLanguageService::class)->requiresTranslation($selected));
    }

    public function test_not_selected_and_russian_do_not_require_translation(): void
    {
        $user = BotUser::create(['chat_id' => 9002, 'platform' => 'telegram']);
        $service = app(ClientLanguageService::class);

        $this->assertFalse($service->requiresTranslation($user));

        $user = $service->select($user, 'ru');
        $this->assertFalse($service->requiresTranslation($user));

        $user = $service->select($user, null);
        $this->assertNull($user->preferred_language_code);
        $this->assertNull($user->preferred_language_name);
        $this->assertNull($user->preferred_language_selected_at);
        $this->assertFalse($service->requiresTranslation($user));
    }

    public function test_disabled_language_cannot_be_selected(): void
    {
        $user = BotUser::create(['chat_id' => 9003, 'platform' => 'telegram']);

        $this->expectException(InvalidArgumentException::class);

        app(ClientLanguageService::class)->select($user, 'de');
    }
}

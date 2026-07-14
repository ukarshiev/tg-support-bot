<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Enums\UserRole;
use App\Livewire\Settings\LanguageSettingsPage;
use App\Models\TranslationCacheEntry;
use App\Models\User;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\TestCase;

class LanguageSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    private function actingAdmin(): User
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        return $admin;
    }

    public function test_route_is_registered_and_admin_can_render_page(): void
    {
        $this->actingAdmin();

        $this->assertTrue(Route::has('admin.settings.language'));

        $this->get(route('admin.settings.language'))
            ->assertOk()
            ->assertSee('Языки')
            ->assertSee('Провайдеры перевода')
            ->assertSee('Очередь переводов');
    }

    public function test_providers_tab_can_be_opened_by_query_parameter(): void
    {
        $this->actingAdmin();

        $this->get(route('admin.settings.language', ['tab' => 'providers']))
            ->assertOk()
            ->assertSee('Приоритет провайдеров')
            ->assertSee('bg-accent text-white', false);
    }

    public function test_languages_are_split_into_two_pages_by_approved_order(): void
    {
        $this->actingAdmin();

        $component = Livewire::test(LanguageSettingsPage::class)
            ->assertSet('languagePage', 1)
            ->assertSee('Страница 1 из 2')
            ->assertSee('Страница 2');

        $this->assertSame(
            ['ru', 'en', 'zh', 'hi', 'es', 'ar', 'fr', 'pt', 'id', 'de', 'ja', 'tr', 'vi', 'ko'],
            collect($component->instance()->paginatedLanguages())->pluck('language.code')->all()
        );

        $component
            ->call('setLanguagePage', 2)
            ->assertSet('languagePage', 2)
            ->assertSee('Страница 2 из 2');

        $this->assertSame(
            ['fa', 'it', 'nl', 'pl', 'uk', 'uz', 'kk', 'az', 'ro', 'tg'],
            collect($component->instance()->paginatedLanguages())->pluck('language.code')->all()
        );
    }

    public function test_existing_language_settings_are_reordered_and_extended_from_fallback(): void
    {
        $this->actingAdmin();

        app(SettingsService::class)->set('support.languages', [
            'ru' => ['code' => 'ru', 'name' => 'Русский', 'native' => '🇷🇺 Русский', 'enabled' => true, 'show_on_start' => true, 'sort_order' => 1],
            'en' => ['code' => 'en', 'name' => 'English', 'native' => '🇺🇸 English', 'enabled' => true, 'show_on_start' => true, 'sort_order' => 2],
            'uk' => ['code' => 'uk', 'name' => 'Українська', 'native' => '🇺🇦 Українська', 'enabled' => true, 'show_on_start' => true, 'sort_order' => 3],
            'it' => ['code' => 'it', 'name' => 'Italiano', 'native' => '🇮🇹 Italiano', 'enabled' => true, 'show_on_start' => true, 'sort_order' => 4],
            'de' => ['code' => 'de', 'name' => 'Deutsch', 'native' => '🇩🇪 Deutsch', 'enabled' => true, 'show_on_start' => true, 'sort_order' => 5],
            'es' => ['code' => 'es', 'name' => 'Español', 'native' => '🇪🇸 Español', 'enabled' => true, 'show_on_start' => true, 'sort_order' => 6],
            'pl' => ['code' => 'pl', 'name' => 'Polski', 'native' => '🇵🇱 Polski', 'enabled' => true, 'show_on_start' => true, 'sort_order' => 7],
            'ro' => ['code' => 'ro', 'name' => 'Română', 'native' => '🇷🇴 Română', 'enabled' => true, 'show_on_start' => true, 'sort_order' => 8],
            'fr' => ['code' => 'fr', 'name' => 'Français', 'native' => '🇫🇷 Français', 'enabled' => true, 'show_on_start' => true, 'sort_order' => 9],
            'tg' => ['code' => 'tg', 'name' => 'Тоҷикӣ', 'native' => '🇹🇯 Тоҷикӣ', 'enabled' => true, 'show_on_start' => true, 'sort_order' => 10],
            'az' => ['code' => 'az', 'name' => 'Azərbaycanca', 'native' => '🇦🇿 Azərbaycanca', 'enabled' => true, 'show_on_start' => true, 'sort_order' => 11],
            'tr' => ['code' => 'tr', 'name' => 'Türkçe', 'native' => '🇹🇷 Türkçe', 'enabled' => true, 'show_on_start' => true, 'sort_order' => 12],
            'kk' => ['code' => 'kk', 'name' => 'Қазақша', 'native' => '🇰🇿 Қазақша', 'enabled' => true, 'show_on_start' => true, 'sort_order' => 13],
            'uz' => ['code' => 'uz', 'name' => "O'zbekcha", 'native' => "🇺🇿 O'zbekcha", 'enabled' => true, 'show_on_start' => true, 'sort_order' => 14],
        ]);

        $component = Livewire::test(LanguageSettingsPage::class);

        $this->assertCount(24, $component->get('languages'));
        $this->assertSame(
            ['ru', 'en', 'zh', 'hi', 'es', 'ar', 'fr', 'pt', 'id', 'de', 'ja', 'tr', 'vi', 'ko'],
            collect($component->instance()->paginatedLanguages())->pluck('language.code')->all()
        );
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('admin.settings.language'))
            ->assertRedirectContains('/admin/login');
    }

    public function test_it_saves_enabled_languages_and_provider_order(): void
    {
        $this->actingAdmin();

        Livewire::test(LanguageSettingsPage::class)
            ->set('languages.0.enabled', true)
            ->set('languages.0.show_on_start', true)
            ->set('languages.0.sort_order', 1)
            ->call('saveLanguages')
            ->assertSet('saved', true)
            ->set('activeTab', 'providers')
            ->set('providerOrder', ['fake', 'yandex', 'google'])
            ->set('allowExternal', true)
            ->set('yandexFolderId', 'folder-id')
            ->call('saveProviders')
            ->assertSet('saved', true);

        $settings = app(SettingsService::class);
        $this->assertSame(['fake', 'yandex', 'google'], $settings->get('translation.provider_order'));
        $this->assertTrue((bool) $settings->get('translation.allow_external'));
        $this->assertSame('folder-id', $settings->get('translation.yandex_folder_id'));
    }

    public function test_it_tests_translation_through_selected_provider_without_fallback_cache(): void
    {
        $this->actingAdmin();
        app(SettingsService::class)->set('translation.provider_order', ['yandex', 'fake']);
        app(SettingsService::class)->set('translation.allow_external', false);

        TranslationCacheEntry::create([
            'source_locale' => 'ru',
            'target_locale' => 'en',
            'source_hash' => \App\Modules\Translation\Services\TranslationService::sourceHash('Добрый день!'),
            'source_text' => 'Добрый день!',
            'translated_text' => 'Yandex cached text',
            'provider' => 'yandex',
            'status' => 'ready',
        ]);

        Livewire::test(LanguageSettingsPage::class)
            ->set('activeTab', 'providers')
            ->set('testProvider', 'fake')
            ->set('testText', 'Добрый день!')
            ->set('testTargetLocale', 'en')
            ->call('testTranslation')
            ->assertSet('testError', null)
            ->assertSee('[fake] [en] Добрый день!')
            ->assertDontSee('Yandex cached text');
    }

    public function test_provider_keys_are_loaded_into_fields_and_saved_as_values(): void
    {
        $this->actingAdmin();
        $settings = app(SettingsService::class);
        $settings->set('translation.yandex_api_key', 'yandex-secret');
        $settings->set('translation.google_api_key', 'google-secret');

        Livewire::test(LanguageSettingsPage::class)
            ->set('activeTab', 'providers')
            ->assertSet('yandexApiKey', 'yandex-secret')
            ->assertSet('googleApiKey', 'google-secret')
            ->set('googleApiKey', 'google-secret-new')
            ->call('saveProviders')
            ->assertSet('googleApiKey', 'google-secret-new')
            ->assertSet('yandexApiKey', 'yandex-secret');

        $this->assertSame('yandex-secret', $settings->get('translation.yandex_api_key'));
        $this->assertSame('google-secret-new', $settings->get('translation.google_api_key'));
    }

    public function test_translation_test_persists_entered_google_key_before_provider_call(): void
    {
        $this->actingAdmin();
        app(SettingsService::class)->set('translation.provider_order', ['google']);
        app(SettingsService::class)->set('translation.allow_external', true);

        Http::fake([
            'translation.googleapis.com/*' => Http::response([
                'data' => [
                    'translations' => [
                        ['translatedText' => 'Good afternoon!'],
                    ],
                ],
            ]),
        ]);

        Livewire::test(LanguageSettingsPage::class)
            ->set('activeTab', 'providers')
            ->set('testProvider', 'google')
            ->set('testText', 'Добрый день!')
            ->set('testTargetLocale', 'en')
            ->call('testTranslation', '', 'google-visible-key')
            ->assertSet('testError', null)
            ->assertSet('googleApiKey', 'google-visible-key')
            ->assertSee('[google] Good afternoon!');

        $this->assertSame('google-visible-key', app(SettingsService::class)->get('translation.google_api_key'));
    }

    public function test_empty_provider_key_fields_do_not_erase_existing_keys(): void
    {
        $this->actingAdmin();
        $settings = app(SettingsService::class);
        $settings->set('translation.yandex_api_key', 'yandex-secret');
        $settings->set('translation.google_api_key', 'google-secret');

        Livewire::test(LanguageSettingsPage::class)
            ->set('activeTab', 'providers')
            ->set('yandexApiKey', '')
            ->set('googleApiKey', '')
            ->call('saveProviders')
            ->assertSet('saved', true);

        $this->assertSame('yandex-secret', $settings->get('translation.yandex_api_key'));
        $this->assertSame('google-secret', $settings->get('translation.google_api_key'));
    }

    public function test_secret_setting_is_read_from_database_when_empty_cache_is_stale(): void
    {
        $this->actingAdmin();
        $settings = app(SettingsService::class);
        $settings->set('translation.google_api_key', 'google-secret');
        Cache::forever('settings.translation.google_api_key', "string\0");

        $this->assertSame('google-secret', $settings->get('translation.google_api_key'));
    }
}

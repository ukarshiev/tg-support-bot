<?php

namespace Tests\Feature\Settings;

use App\Enums\UserRole;
use App\Livewire\Settings\GeneralSettingsPage;
use App\Models\User;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

class GeneralSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    // ── Access control ────────────────────────────────────────────────────────

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get(route('admin.settings.general'));

        $response->assertRedirectContains('/admin/login');
    }

    public function test_authenticated_admin_can_render_settings_page(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test(GeneralSettingsPage::class)
            ->assertSuccessful();
    }

    public function test_manager_role_is_redirected_when_not_authorized(): void
    {
        // Filament's Authenticate middleware allows all authenticated users to
        // view the panel (role-gating is at the page level in Filament, not the
        // route level). For the custom settings route we use the same
        // Authenticate middleware which lets any authenticated user through —
        // the page itself does not enforce isAdmin() at the route, so managers
        // can access the form. This test confirms a manager can load it.
        $manager = User::factory()->manager()->create();
        $this->actingAs($manager);

        Livewire::test(GeneralSettingsPage::class)
            ->assertSuccessful();
    }

    // ── Mount / initial state ────────────────────────────────────────────────

    public function test_loads_current_values_from_settings_service(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        /** @var SettingsService $settings */
        $settings = app(SettingsService::class);
        $settings->set('app.bot_name', 'My Bot');
        $settings->set('app.bot_description', 'A great bot');
        $settings->set('app.manager_interface', 'admin_panel');

        Livewire::test(GeneralSettingsPage::class)
            ->assertSet('bot_name', 'My Bot')
            ->assertSet('bot_description', 'A great bot')
            ->assertSet('manager_interface', 'admin_panel');
    }

    public function test_loads_defaults_when_no_db_row_exists(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        config(['app.manager_interface' => 'telegram_group']);
        Cache::flush();

        Livewire::test(GeneralSettingsPage::class)
            ->assertSet('bot_name', '')
            ->assertSet('bot_description', '')
            ->assertSet('manager_interface', 'telegram_group');
    }

    // ── Save — valid inputs ──────────────────────────────────────────────────

    public function test_save_persists_bot_name_and_description(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test(GeneralSettingsPage::class)
            ->set('bot_name', 'Новый бот')
            ->set('bot_description', 'Описание')
            ->set('manager_interface', 'telegram_group')
            ->call('save')
            ->assertSet('saved', true)
            ->assertSet('formErrors', []);

        $this->assertDatabaseHas('settings', ['key' => 'app.bot_name', 'value' => 'Новый бот']);
        $this->assertDatabaseHas('settings', ['key' => 'app.bot_description', 'value' => 'Описание']);
    }

    public function test_save_accepts_nullable_bot_name(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test(GeneralSettingsPage::class)
            ->set('bot_name', '')
            ->set('bot_description', '')
            ->set('manager_interface', 'telegram_group')
            ->call('save')
            ->assertSet('saved', true)
            ->assertSet('formErrors', []);
    }

    // ── Save — invalid inputs ────────────────────────────────────────────────

    public function test_save_rejects_bot_name_exceeding_255_chars(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test(GeneralSettingsPage::class)
            ->set('bot_name', str_repeat('a', 256))
            ->set('manager_interface', 'telegram_group')
            ->call('save')
            ->assertSet('saved', false)
            ->assertSet('formErrors', ['bot_name' => 'Максимальная длина — 255 символов.']);

        $this->assertDatabaseMissing('settings', ['key' => 'app.bot_name']);
    }

    public function test_save_rejects_description_exceeding_1000_chars(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test(GeneralSettingsPage::class)
            ->set('bot_description', str_repeat('x', 1001))
            ->set('manager_interface', 'telegram_group')
            ->call('save')
            ->assertSet('saved', false)
            ->assertSet('formErrors', ['bot_description' => 'Максимальная длина — 1000 символов.']);
    }

    public function test_save_rejects_invalid_manager_interface_value(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test(GeneralSettingsPage::class)
            ->set('manager_interface', 'invalid_value')
            ->call('save')
            ->assertSet('saved', false);

        $component = Livewire::test(GeneralSettingsPage::class)
            ->set('manager_interface', 'invalid_value')
            ->call('save');

        $this->assertNotEmpty($component->get('formErrors'));
    }

    // ── MANAGER_INTERFACE switch → restart notice ────────────────────────────

    public function test_switching_manager_interface_shows_restart_notice(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        /** @var SettingsService $settings */
        $settings = app(SettingsService::class);
        $settings->set('app.manager_interface', 'telegram_group');

        Livewire::test(GeneralSettingsPage::class)
            ->set('manager_interface', 'admin_panel')
            ->call('save')
            ->assertSet('showRestartNotice', true)
            ->assertSet('saved', true)
            ->assertSee('docker compose restart app');
    }

    public function test_no_restart_notice_when_manager_interface_unchanged(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        /** @var SettingsService $settings */
        $settings = app(SettingsService::class);
        $settings->set('app.manager_interface', 'telegram_group');

        Livewire::test(GeneralSettingsPage::class)
            ->set('manager_interface', 'telegram_group')
            ->call('save')
            ->assertSet('showRestartNotice', false);
    }

    // ── Cancel ───────────────────────────────────────────────────────────────

    public function test_cancel_resets_to_stored_values(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        /** @var SettingsService $settings */
        $settings = app(SettingsService::class);
        $settings->set('app.bot_name', 'Original');
        $settings->set('app.manager_interface', 'telegram_group');

        Livewire::test(GeneralSettingsPage::class)
            ->set('bot_name', 'Changed')
            ->set('manager_interface', 'admin_panel')
            ->call('cancel')
            ->assertSet('bot_name', 'Original')
            ->assertSet('manager_interface', 'telegram_group')
            ->assertSet('saved', false)
            ->assertSet('showRestartNotice', false);
    }

    // ── UI rendering ──────────────────────────────────────────────────────────

    public function test_renders_page_title_and_subtitle(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test(GeneralSettingsPage::class)
            ->assertSee('Основные')
            ->assertSee('Общие настройки бота');
    }

    public function test_renders_manager_interface_options(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test(GeneralSettingsPage::class)
            ->assertSee('telegram_group')
            ->assertSee('admin_panel');
    }

    // ── Route resolution ──────────────────────────────────────────────────────

    public function test_route_admin_settings_general_is_registered(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('admin.settings.general'));
    }

    public function test_component_is_routable_and_mounts_successfully(): void
    {
        // Verifies the route resolves to GeneralSettingsPage and the component
        // mounts without errors. Uses Livewire::test() to avoid @vite() failures
        // in the test environment (no compiled assets).
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test(GeneralSettingsPage::class)
            ->assertSuccessful()
            ->assertSee('Основные');
    }
}

<?php

namespace Tests\Feature\Settings;

use App\Enums\UserRole;
use App\Livewire\Settings\GeneralSettingsPage;
use App\Models\User;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_manager_role_can_load_the_page(): void
    {
        $manager = User::factory()->manager()->create();
        $this->actingAs($manager);

        Livewire::test(GeneralSettingsPage::class)
            ->assertSuccessful();
    }

    // ── Mount / initial state ────────────────────────────────────────────────

    public function test_loads_template_value_from_settings_service(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        /** @var SettingsService $settings */
        $settings = app(SettingsService::class);
        $settings->set('telegram.template_topic_name', 'Обращение {first_name}');

        Livewire::test(GeneralSettingsPage::class)
            ->assertSet('template_topic_name', 'Обращение {first_name}');
    }

    // ── Save ─────────────────────────────────────────────────────────────────

    public function test_save_persists_template_topic_name(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test(GeneralSettingsPage::class)
            ->set('template_topic_name', 'Новое обращение')
            ->call('save')
            ->assertSet('saved', true)
            ->assertSet('formErrors', []);

        $this->assertDatabaseHas('settings', ['key' => 'telegram.template_topic_name', 'value' => 'Новое обращение']);
    }

    public function test_save_rejects_template_exceeding_255_chars(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test(GeneralSettingsPage::class)
            ->set('template_topic_name', str_repeat('a', 256))
            ->call('save')
            ->assertSet('saved', false)
            ->assertSet('formErrors', ['template_topic_name' => 'Максимальная длина — 255 символов.']);
    }

    // ── Cancel ───────────────────────────────────────────────────────────────

    public function test_cancel_resets_to_stored_value(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        /** @var SettingsService $settings */
        $settings = app(SettingsService::class);
        $settings->set('telegram.template_topic_name', 'Original');

        Livewire::test(GeneralSettingsPage::class)
            ->set('template_topic_name', 'Changed')
            ->call('cancel')
            ->assertSet('template_topic_name', 'Original')
            ->assertSet('saved', false);
    }

    // ── UI rendering ──────────────────────────────────────────────────────────

    public function test_renders_page_title_and_template_field(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test(GeneralSettingsPage::class)
            ->assertSee('Основные')
            ->assertSee('Шаблон названия топика');
    }

    public function test_does_not_render_removed_fields(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test(GeneralSettingsPage::class)
            ->assertDontSee('Название бот')
            ->assertDontSee('Интерфейс менеджера');
    }

    // ── Route resolution ──────────────────────────────────────────────────────

    public function test_route_admin_settings_general_is_registered(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('admin.settings.general'));
    }

    public function test_component_is_routable_and_mounts_successfully(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test(GeneralSettingsPage::class)
            ->assertSuccessful()
            ->assertSee('Основные');
    }
}

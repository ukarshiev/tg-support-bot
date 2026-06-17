<?php

namespace Tests\Feature\Settings;

use App\Enums\UserRole;
use App\Livewire\Settings\GeneralSettingsPage;
use App\Models\User;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class GeneralSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Configure a valid Telegram token and fake the verify-before-save calls
     * (getMe + getChat + getChatMember=administrator) so saving a group_id passes.
     */
    private function fakeTelegramGroupVerifyOk(): void
    {
        app(SettingsService::class)->set('telegram.token', 'bot123:valid');

        Http::fake([
            'https://api.telegram.org/*/getMe' => Http::response(['ok' => true, 'result' => ['id' => 7, 'username' => 'bot']], 200),
            'https://api.telegram.org/*/getChat' => Http::response(['ok' => true, 'result' => ['id' => -1001234567890, 'type' => 'supergroup']], 200),
            'https://api.telegram.org/*/getChatMember' => Http::response(['ok' => true, 'result' => ['status' => 'administrator']], 200),
        ]);
    }

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

    public function test_loads_group_id_from_settings_service(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        /** @var SettingsService $settings */
        $settings = app(SettingsService::class);
        $settings->set('telegram.group_id', '-1009876543');

        Livewire::test(GeneralSettingsPage::class)
            ->assertSet('group_id', '-1009876543');
    }

    // ── Save ─────────────────────────────────────────────────────────────────

    public function test_save_persists_template_topic_name(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);
        $this->fakeTelegramGroupVerifyOk();

        Livewire::test(GeneralSettingsPage::class)
            ->set('template_topic_name', 'Новое обращение')
            ->set('group_id', '-100123')
            ->call('save')
            ->assertSet('saved', true)
            ->assertSet('formErrors', []);

        $this->assertDatabaseHas('settings', ['key' => 'telegram.template_topic_name', 'value' => 'Новое обращение']);
    }

    public function test_save_persists_group_id(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);
        $this->fakeTelegramGroupVerifyOk();

        Livewire::test(GeneralSettingsPage::class)
            ->set('group_id', '-1001234567890')
            ->call('save')
            ->assertSet('saved', true)
            ->assertSet('formErrors', []);

        $this->assertDatabaseHas('settings', ['key' => 'telegram.group_id', 'value' => '-1001234567890']);
    }

    public function test_save_rejects_group_id_when_bot_not_admin(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        app(SettingsService::class)->set('telegram.token', 'bot123:valid');
        Http::fake([
            'https://api.telegram.org/*/getMe' => Http::response(['ok' => true, 'result' => ['id' => 7, 'username' => 'bot']], 200),
            'https://api.telegram.org/*/getChat' => Http::response(['ok' => true, 'result' => ['id' => -1001234567890, 'type' => 'supergroup']], 200),
            'https://api.telegram.org/*/getChatMember' => Http::response(['ok' => true, 'result' => ['status' => 'member']], 200),
        ]);

        $component = Livewire::test(GeneralSettingsPage::class)
            ->set('group_id', '-1001234567890')
            ->call('save')
            ->assertSet('saved', false);

        $this->assertArrayHasKey('group_id', $component->get('formErrors'));
        $this->assertDatabaseMissing('settings', ['key' => 'telegram.group_id', 'value' => '-1001234567890']);
    }

    public function test_save_rejects_group_id_when_token_not_configured(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        $component = Livewire::test(GeneralSettingsPage::class)
            ->set('group_id', '-1001234567890')
            ->call('save')
            ->assertSet('saved', false);

        $this->assertArrayHasKey('group_id', $component->get('formErrors'));
    }

    public function test_save_allows_blank_group_id(): void
    {
        // The Telegram supergroup is optional — blank group_id means admin-only
        // (no group mirroring) and must save successfully.
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        $component = Livewire::test(GeneralSettingsPage::class)
            ->set('group_id', '')
            ->call('save')
            ->assertSet('saved', true);

        $this->assertArrayNotHasKey('group_id', $component->get('formErrors'));
        $this->assertDatabaseHas('settings', ['key' => 'telegram.group_id', 'value' => '']);
    }

    public function test_save_rejects_group_id_exceeding_50_chars(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        $component = Livewire::test(GeneralSettingsPage::class)
            ->set('group_id', str_repeat('1', 51))
            ->call('save')
            ->assertSet('saved', false);

        $this->assertArrayHasKey('group_id', $component->get('formErrors'));
        $this->assertSame('Максимальная длина — 50 символов.', $component->get('formErrors')['group_id']);
    }

    public function test_save_rejects_template_exceeding_255_chars(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test(GeneralSettingsPage::class)
            ->set('template_topic_name', str_repeat('a', 256))
            ->set('group_id', '-100123')
            ->call('save')
            ->assertSet('saved', false)
            ->assertSet('formErrors', ['template_topic_name' => 'Максимальная длина — 255 символов.']);
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

    public function test_renders_group_id_field(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test(GeneralSettingsPage::class)
            ->assertSee('ID группы для приёма сообщений');
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

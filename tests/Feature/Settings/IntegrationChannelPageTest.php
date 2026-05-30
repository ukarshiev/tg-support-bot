<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Enums\UserRole;
use App\Livewire\Settings\IntegrationChannelPage;
use App\Models\User;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class IntegrationChannelPageTest extends TestCase
{
    use RefreshDatabase;

    // ── Access control ────────────────────────────────────────────────────────

    public function test_guest_is_redirected_to_login_on_channel_page(): void
    {
        $response = $this->get(route('admin.settings.integrations.channel', ['channel' => 'telegram']));

        $response->assertRedirectContains('/admin/login');
    }

    public function test_authenticated_admin_can_render_telegram_channel_page(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test(IntegrationChannelPage::class, ['channel' => 'telegram'])
            ->assertSuccessful();
    }

    // ── Route registration ────────────────────────────────────────────────────

    public function test_route_admin_settings_integrations_channel_is_registered(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('admin.settings.integrations.channel'));
    }

    // ── Mount / initial state ────────────────────────────────────────────────

    public function test_mount_loads_telegram_fields_from_settings_service(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        /** @var SettingsService $settings */
        $settings = app(SettingsService::class);
        $settings->set('telegram.group_id', '-1009999');

        Livewire::test(IntegrationChannelPage::class, ['channel' => 'telegram'])
            ->assertSet('channel', 'telegram')
            ->assertSet('telegram_group_id', '-1009999');
    }

    public function test_mount_sets_channel_connected_when_telegram_configured(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        /** @var SettingsService $settings */
        $settings = app(SettingsService::class);
        $settings->set('telegram.token', 'tok');
        $settings->set('telegram.secret_key', 'sec');
        $settings->set('telegram.group_id', '-100123');

        Livewire::test(IntegrationChannelPage::class, ['channel' => 'telegram'])
            ->assertSet('channelConnected', true);
    }

    public function test_mount_sets_channel_not_connected_when_keys_absent(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        // Ensure no config fallback provides values
        \Illuminate\Support\Facades\Cache::flush();
        config([
            'traffic_source.settings.telegram.token' => '',
            'traffic_source.settings.telegram.secret_key' => '',
            'traffic_source.settings.telegram.group_id' => '',
        ]);

        Livewire::test(IntegrationChannelPage::class, ['channel' => 'telegram'])
            ->assertSet('channelConnected', false);
    }

    // ── Rendering ─────────────────────────────────────────────────────────────

    public function test_renders_telegram_form_fields(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test(IntegrationChannelPage::class, ['channel' => 'telegram'])
            ->assertSee('Токен бота')
            ->assertSee('Секретный ключ Webhook')
            ->assertSee('ID группы')
            ->assertSee('Подключить');
    }

    public function test_renders_vk_form_fields(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test(IntegrationChannelPage::class, ['channel' => 'vk'])
            ->assertSee('Токен')
            ->assertSee('Код подтверждения')
            ->assertSee('Подключить');
    }

    public function test_renders_max_form_fields(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test(IntegrationChannelPage::class, ['channel' => 'max'])
            ->assertSee('Токен')
            ->assertSee('Секретный ключ Webhook')
            ->assertSee('Подключить');
    }

    public function test_renders_instruction_panel_for_telegram(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test(IntegrationChannelPage::class, ['channel' => 'telegram'])
            ->assertSee('Инструкция')
            ->assertSee('Создайте группу в Telegram')
            ->assertSee('Добавьте бота как администратора')
            ->assertSee('Подробнее в документации');
    }

    public function test_renders_instruction_panel_for_vk(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test(IntegrationChannelPage::class, ['channel' => 'vk'])
            ->assertSee('Инструкция')
            ->assertSee('Создайте сообщество VK')
            ->assertSee('Подробнее в документации');
    }

    public function test_renders_instruction_panel_for_max(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test(IntegrationChannelPage::class, ['channel' => 'max'])
            ->assertSee('Инструкция')
            ->assertSee('Создайте бота в платформе MAX')
            ->assertSee('Подробнее в документации');
    }

    public function test_renders_breadcrumb_with_channel_name(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test(IntegrationChannelPage::class, ['channel' => 'telegram'])
            ->assertSee('Интеграции')
            ->assertSee('Подключение')
            ->assertSee('Telegram');
    }

    // ── Save — valid inputs ───────────────────────────────────────────────────

    public function test_save_telegram_persists_group_id(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test(IntegrationChannelPage::class, ['channel' => 'telegram'])
            ->set('telegram_group_id', '-1001234567890')
            ->call('save')
            ->assertSet('saved', true)
            ->assertSet('formErrors', []);

        $this->assertDatabaseHas('settings', ['key' => 'telegram.group_id', 'value' => '-1001234567890']);
    }

    public function test_save_telegram_does_not_overwrite_token_when_field_empty(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        // Pre-set a token in DB
        /** @var SettingsService $settings */
        $settings = app(SettingsService::class);
        $settings->set('telegram.token', 'existing_tok');

        Livewire::test(IntegrationChannelPage::class, ['channel' => 'telegram'])
            ->set('telegram_token', '')       // blank — should NOT overwrite
            ->set('telegram_group_id', '-100123')
            ->call('save')
            ->assertSet('saved', true);

        // The pre-existing token must still be in DB (as encrypted value)
        $this->assertDatabaseHas('settings', ['key' => 'telegram.token']);
    }

    public function test_save_vk_persists_credentials(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test(IntegrationChannelPage::class, ['channel' => 'vk'])
            ->set('vk_token', 'vk_tok')
            ->set('vk_secret_key', 'sec')
            ->set('vk_confirm_code', 'code')
            ->call('save')
            ->assertSet('saved', true);

        // Secrets stored encrypted — key must exist
        $this->assertDatabaseHas('settings', ['key' => 'vk.token']);
        $this->assertDatabaseHas('settings', ['key' => 'vk.secret_key']);
        $this->assertDatabaseHas('settings', ['key' => 'vk.confirm_code']);
    }

    public function test_save_max_persists_credentials(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test(IntegrationChannelPage::class, ['channel' => 'max'])
            ->set('max_token', 'max_tok')
            ->set('max_secret_key', 'max_sec')
            ->call('save')
            ->assertSet('saved', true);

        $this->assertDatabaseHas('settings', ['key' => 'max.token']);
        $this->assertDatabaseHas('settings', ['key' => 'max.secret_key']);
    }

    // ── Save — invalid inputs ─────────────────────────────────────────────────

    public function test_save_telegram_rejects_group_id_exceeding_50_chars(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test(IntegrationChannelPage::class, ['channel' => 'telegram'])
            ->set('telegram_group_id', str_repeat('1', 51))
            ->call('save')
            ->assertSet('saved', false);

        $component = Livewire::test(IntegrationChannelPage::class, ['channel' => 'telegram'])
            ->set('telegram_group_id', str_repeat('1', 51))
            ->call('save');

        $this->assertNotEmpty($component->get('formErrors'));
    }

    // ── Cancel ────────────────────────────────────────────────────────────────

    public function test_cancel_resets_form_to_stored_values(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        /** @var SettingsService $settings */
        $settings = app(SettingsService::class);
        $settings->set('telegram.group_id', '-100original');

        Livewire::test(IntegrationChannelPage::class, ['channel' => 'telegram'])
            ->set('telegram_group_id', '-100changed')
            ->call('cancel')
            ->assertSet('telegram_group_id', '-100original')
            ->assertSet('saved', false)
            ->assertSet('webhookMessage', null);
    }

    // ── Connect (save + webhook) ──────────────────────────────────────────────

    public function test_connect_saves_and_registers_webhook_for_telegram(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'description' => 'Webhook set'], 200),
        ]);

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test(IntegrationChannelPage::class, ['channel' => 'telegram'])
            ->set('telegram_group_id', '-100123')
            ->set('telegram_token', 'bot123:validtoken')
            ->set('telegram_secret_key', 'secret')
            ->call('connect')
            ->assertSet('saved', true)
            ->assertSet('webhookSuccess', true);
    }

    public function test_connect_does_not_register_webhook_when_save_fails(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test(IntegrationChannelPage::class, ['channel' => 'telegram'])
            ->set('telegram_group_id', str_repeat('x', 51))  // too long — save will fail
            ->call('connect')
            ->assertSet('saved', false)
            ->assertSet('webhookSuccess', false)
            ->assertSet('webhookMessage', null);
    }

    public function test_connect_shows_webhook_error_when_api_fails(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401),
        ]);

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        /** @var SettingsService $settings */
        $settings = app(SettingsService::class);
        $settings->set('telegram.token', 'bad_token');

        Livewire::test(IntegrationChannelPage::class, ['channel' => 'telegram'])
            ->set('telegram_group_id', '-100123')
            ->set('telegram_token', 'bad_token')
            ->call('connect')
            ->assertSet('saved', true)
            ->assertSet('webhookSuccess', false);
    }

    // ── Webhook registration (standalone, backward-compat) ────────────────────

    public function test_register_webhook_telegram_success(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'description' => 'Webhook set'], 200),
        ]);

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        /** @var SettingsService $settings */
        $settings = app(SettingsService::class);
        $settings->set('telegram.token', 'bot123:validtoken');
        $settings->set('telegram.secret_key', 'secret');

        Livewire::test(IntegrationChannelPage::class, ['channel' => 'telegram'])
            ->call('registerWebhook')
            ->assertSet('webhookSuccess', true);
    }

    public function test_register_webhook_telegram_error_on_api_failure(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401),
        ]);

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        /** @var SettingsService $settings */
        $settings = app(SettingsService::class);
        $settings->set('telegram.token', 'bad_token');
        $settings->set('telegram.secret_key', 'sec');

        Livewire::test(IntegrationChannelPage::class, ['channel' => 'telegram'])
            ->call('registerWebhook')
            ->assertSet('webhookSuccess', false);
    }

    public function test_register_webhook_max_success(): void
    {
        Http::fake([
            'https://platform-api.max.ru/*' => Http::response(['result' => 'ok'], 200),
        ]);

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        /** @var SettingsService $settings */
        $settings = app(SettingsService::class);
        $settings->set('max.token', 'max_token');

        Livewire::test(IntegrationChannelPage::class, ['channel' => 'max'])
            ->call('registerWebhook')
            ->assertSet('webhookSuccess', true);
    }

    public function test_register_webhook_shows_error_when_token_not_set(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test(IntegrationChannelPage::class, ['channel' => 'telegram'])
            ->call('registerWebhook')
            ->assertSet('webhookSuccess', false);

        $component = Livewire::test(IntegrationChannelPage::class, ['channel' => 'telegram'])
            ->call('registerWebhook');

        $this->assertNotEmpty($component->get('webhookMessage'));
    }
}

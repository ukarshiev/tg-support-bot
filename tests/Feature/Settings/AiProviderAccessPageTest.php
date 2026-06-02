<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Enums\UserRole;
use App\Livewire\Settings\AiProviderAccessPage;
use App\Models\User;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AiProviderAccessPageTest extends TestCase
{
    use RefreshDatabase;

    // ── Access control ────────────────────────────────────────────────────────

    public function test_guest_is_redirected_to_login_on_provider_page(): void
    {
        $response = $this->get(route('admin.settings.ai.provider', ['provider' => 'openai']));

        $response->assertRedirectContains('/admin/login');
    }

    public function test_authenticated_admin_can_render_openai_page(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test(AiProviderAccessPage::class, ['provider' => 'openai'])
            ->assertSuccessful();
    }

    public function test_authenticated_admin_can_render_deepseek_page(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test(AiProviderAccessPage::class, ['provider' => 'deepseek'])
            ->assertSuccessful();
    }

    public function test_authenticated_admin_can_render_gigachat_page(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test(AiProviderAccessPage::class, ['provider' => 'gigachat'])
            ->assertSuccessful();
    }

    // ── Route registration ────────────────────────────────────────────────────

    public function test_route_admin_settings_ai_provider_is_registered(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('admin.settings.ai.provider'));
    }

    // ── Mount / initial state ────────────────────────────────────────────────

    public function test_mount_loads_openai_non_secret_fields(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        /** @var SettingsService $settings */
        $settings = app(SettingsService::class);
        $settings->set('ai.openai_model', 'gpt-4o');
        $settings->set('ai.openai_base_url', 'https://api.openai.com/v1');

        Livewire::test(AiProviderAccessPage::class, ['provider' => 'openai'])
            ->assertSet('openai_model', 'gpt-4o')
            ->assertSet('openai_base_url', 'https://api.openai.com/v1')
            ->assertSet('openai_api_key', null);
    }

    public function test_mount_does_not_prefill_secret_fields(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test(AiProviderAccessPage::class, ['provider' => 'openai'])
            ->assertSet('openai_api_key', null);

        Livewire::test(AiProviderAccessPage::class, ['provider' => 'deepseek'])
            ->assertSet('deepseek_client_secret', null);

        Livewire::test(AiProviderAccessPage::class, ['provider' => 'gigachat'])
            ->assertSet('gigachat_client_secret', null);
    }

    // ── Save — OpenAI ─────────────────────────────────────────────────────────

    public function test_save_openai_persists_non_secret_fields(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test(AiProviderAccessPage::class, ['provider' => 'openai'])
            ->set('openai_model', 'gpt-4-turbo')
            ->set('openai_base_url', 'https://api.openai.com/v1')
            ->set('openai_temperature', '0.5')
            ->call('save')
            ->assertSet('saved', true);

        /** @var SettingsService $settings */
        $settings = app(SettingsService::class);
        $this->assertSame('gpt-4-turbo', (string) $settings->get('ai.openai_model'));
        $this->assertSame('https://api.openai.com/v1', (string) $settings->get('ai.openai_base_url'));
    }

    public function test_save_openai_does_not_store_blank_api_key(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        // Pre-store a key
        /** @var SettingsService $settings */
        $settings = app(SettingsService::class);
        $settings->set('ai.openai_api_key', 'sk-original');

        Livewire::test(AiProviderAccessPage::class, ['provider' => 'openai'])
            ->set('openai_api_key', '')
            ->call('save')
            ->assertSet('saved', true);

        // Original key must not be overwritten
        $this->assertSame('sk-original', (string) $settings->get('ai.openai_api_key'));
    }

    public function test_save_openai_stores_non_blank_api_key(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test(AiProviderAccessPage::class, ['provider' => 'openai'])
            ->set('openai_api_key', 'sk-new-key')
            ->call('save')
            ->assertSet('saved', true);

        /** @var SettingsService $settings */
        $settings = app(SettingsService::class);
        $this->assertSame('sk-new-key', (string) $settings->get('ai.openai_api_key'));
    }

    public function test_save_openai_rejects_zero_max_tokens(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test(AiProviderAccessPage::class, ['provider' => 'openai'])
            ->set('openai_max_tokens', 0)
            ->call('save')
            ->assertSet('saved', false);
    }

    // ── Save — DeepSeek ───────────────────────────────────────────────────────

    public function test_save_deepseek_does_not_overwrite_blank_client_secret(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        /** @var SettingsService $settings */
        $settings = app(SettingsService::class);
        $settings->set('ai.deepseek_client_secret', 'original-secret');

        Livewire::test(AiProviderAccessPage::class, ['provider' => 'deepseek'])
            ->set('deepseek_client_secret', '')
            ->call('save')
            ->assertSet('saved', true);

        $this->assertSame('original-secret', (string) $settings->get('ai.deepseek_client_secret'));
    }

    public function test_save_deepseek_persists_non_blank_client_secret(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test(AiProviderAccessPage::class, ['provider' => 'deepseek'])
            ->set('deepseek_client_id', 'my-client')
            ->set('deepseek_client_secret', 'new-secret')
            ->call('save')
            ->assertSet('saved', true);

        /** @var SettingsService $settings */
        $settings = app(SettingsService::class);
        $this->assertSame('new-secret', (string) $settings->get('ai.deepseek_client_secret'));
    }

    // ── Save — GigaChat ───────────────────────────────────────────────────────

    public function test_save_gigachat_uploads_certificate_file(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        $target = storage_path('certs/russian_trusted_root_ca_pem.crt');
        @unlink($target);

        $cert = \Illuminate\Http\UploadedFile::fake()->createWithContent(
            'my-ca.crt',
            "-----BEGIN CERTIFICATE-----\nTEST\n-----END CERTIFICATE-----"
        );

        Livewire::test(AiProviderAccessPage::class, ['provider' => 'gigachat'])
            ->set('gigachat_cert_file', $cert)
            ->call('save')
            ->assertSet('saved', true);

        /** @var SettingsService $settings */
        $settings = app(SettingsService::class);
        // The stored path is the fixed storage-relative location used by GigaChatProvider (storage_path()).
        $this->assertSame('certs/russian_trusted_root_ca_pem.crt', (string) $settings->get('ai.gigachat_path_cert'));
        $this->assertFileExists($target);

        @unlink($target);
    }

    public function test_save_gigachat_rejects_non_certificate_file(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        $bad = \Illuminate\Http\UploadedFile::fake()->create('notes.txt', 1);

        $component = Livewire::test(AiProviderAccessPage::class, ['provider' => 'gigachat'])
            ->set('gigachat_cert_file', $bad)
            ->call('save')
            ->assertSet('saved', false);

        $this->assertArrayHasKey('gigachat_cert_file', $component->get('formErrors'));
    }

    public function test_save_gigachat_does_not_overwrite_blank_client_secret(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        /** @var SettingsService $settings */
        $settings = app(SettingsService::class);
        $settings->set('ai.gigachat_client_secret', 'gc-original');

        Livewire::test(AiProviderAccessPage::class, ['provider' => 'gigachat'])
            ->set('gigachat_client_secret', '')
            ->call('save')
            ->assertSet('saved', true);

        $this->assertSame('gc-original', (string) $settings->get('ai.gigachat_client_secret'));
    }

    // ── Cancel ───────────────────────────────────────────────────────────────

    public function test_cancel_resets_openai_fields_to_stored_values(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        /** @var SettingsService $settings */
        $settings = app(SettingsService::class);
        $settings->set('ai.openai_model', 'gpt-4o');

        Livewire::test(AiProviderAccessPage::class, ['provider' => 'openai'])
            ->set('openai_model', 'changed-model')
            ->call('cancel')
            ->assertSet('openai_model', 'gpt-4o')
            ->assertSet('saved', false)
            ->assertSet('openai_api_key', null);
    }
}

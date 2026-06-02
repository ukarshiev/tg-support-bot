<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Enums\UserRole;
use App\Livewire\Settings\AiAssistantPage;
use App\Models\User;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AiAssistantPageTest extends TestCase
{
    use RefreshDatabase;

    // ── Access control ────────────────────────────────────────────────────────

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get(route('admin.settings.ai'));

        $response->assertRedirectContains('/admin/login');
    }

    public function test_authenticated_admin_can_render_ai_assistant_page(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test(AiAssistantPage::class)
            ->assertSuccessful();
    }

    // ── Route registration ────────────────────────────────────────────────────

    public function test_route_admin_settings_ai_is_registered(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('admin.settings.ai'));
    }

    // ── Mount / initial state ────────────────────────────────────────────────

    public function test_mount_loads_values_from_settings_service(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        /** @var SettingsService $settings */
        $settings = app(SettingsService::class);
        $settings->set('ai.enabled', true);
        $settings->set('ai.default_provider', 'deepseek');
        $settings->set('ai.auto_reply', false);
        $settings->set('ai.max_context_tokens', 4000);
        $settings->set('ai.system_prompt', 'Be helpful.');

        Livewire::test(AiAssistantPage::class)
            ->assertSet('ai_enabled', true)
            ->assertSet('default_provider', 'deepseek')
            ->assertSet('auto_reply', false)
            ->assertSet('max_context_tokens', 4000)
            ->assertSet('system_prompt', 'Be helpful.');
    }

    public function test_mount_uses_defaults_when_no_settings_stored(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        config([
            'ai.enabled' => false,
            'ai.default_provider' => 'openai',
            'ai.auto_reply' => false,
            'ai.max_context_tokens' => 3000,
        ]);

        Livewire::test(AiAssistantPage::class)
            ->assertSet('ai_enabled', false)
            ->assertSet('default_provider', 'openai')
            ->assertSet('auto_reply', false)
            ->assertSet('max_context_tokens', 3000);
    }

    // ── Save ─────────────────────────────────────────────────────────────────

    public function test_save_persists_all_ai_settings(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test(AiAssistantPage::class)
            ->set('ai_enabled', true)
            ->set('default_provider', 'gigachat')
            ->set('auto_reply', false)
            ->set('max_context_tokens', 5000)
            ->set('system_prompt', 'My prompt')
            ->call('save')
            ->assertSet('saved', true)
            ->assertHasNoErrors();

        /** @var SettingsService $settings */
        $settings = app(SettingsService::class);
        $this->assertTrue((bool) $settings->get('ai.enabled'));
        $this->assertSame('gigachat', (string) $settings->get('ai.default_provider'));
        $this->assertSame(5000, (int) $settings->get('ai.max_context_tokens'));
        $this->assertSame('My prompt', (string) $settings->get('ai.system_prompt'));
    }

    public function test_save_rejects_invalid_provider(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        $component = Livewire::test(AiAssistantPage::class)
            ->set('ai_enabled', true)
            ->set('default_provider', 'anthropic')
            ->call('save')
            ->assertSet('saved', false);

        $this->assertArrayHasKey('default_provider', $component->get('formErrors'));
    }

    public function test_save_rejects_zero_max_context_tokens(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test(AiAssistantPage::class)
            ->set('ai_enabled', true)
            ->set('max_context_tokens', 0)
            ->call('save')
            ->assertSet('saved', false);
    }

    // ── Auto-reply confirm flow ───────────────────────────────────────────────

    public function test_auto_reply_toggle_shows_warning_instead_of_saving(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        // Setting auto_reply to true triggers the updatedAutoReply lifecycle hook
        Livewire::test(AiAssistantPage::class)
            ->set('auto_reply', true)
            ->assertSet('auto_reply', false)
            ->assertSet('showAutoReplyWarning', true);
    }

    public function test_confirm_auto_reply_enables_auto_reply(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test(AiAssistantPage::class)
            ->set('auto_reply', true)
            ->call('confirmAutoReply')
            ->assertSet('auto_reply', true)
            ->assertSet('showAutoReplyWarning', false);
    }

    public function test_cancel_auto_reply_keeps_auto_reply_disabled(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test(AiAssistantPage::class)
            ->set('auto_reply', true)
            ->call('cancelAutoReply')
            ->assertSet('auto_reply', false)
            ->assertSet('showAutoReplyWarning', false);
    }

    // ── Cancel ───────────────────────────────────────────────────────────────

    public function test_cancel_resets_to_stored_values(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        /** @var SettingsService $settings */
        $settings = app(SettingsService::class);
        $settings->set('ai.default_provider', 'openai');
        $settings->set('ai.system_prompt', 'Original prompt');

        Livewire::test(AiAssistantPage::class)
            ->set('default_provider', 'gigachat')
            ->set('system_prompt', 'Changed')
            ->call('cancel')
            ->assertSet('default_provider', 'openai')
            ->assertSet('system_prompt', 'Original prompt')
            ->assertSet('saved', false);
    }
}

<?php

namespace Tests\Unit\Livewire\Settings;

use App\Livewire\Settings\AiAssistantPage;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Unit-level tests for AiAssistantPage Livewire component.
 *
 * Focuses on business logic: mount(), save(), cancel(),
 * updatedAutoReply(), confirmAutoReply(), cancelAutoReply()
 * using a mocked SettingsService.
 */
class AiAssistantPageTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    // ── mount ────────────────────────────────────────────────────────────────────

    public function test_mount_populates_properties_from_settings_service(): void
    {
        /** @var \Mockery\MockInterface&SettingsService $mock */
        $mock = Mockery::mock(SettingsService::class);
        $mock->shouldReceive('get')->with('ai.enabled')->andReturn(true);
        $mock->shouldReceive('get')->with('ai.default_provider')->andReturn('deepseek');
        $mock->shouldReceive('get')->with('ai.auto_reply')->andReturn(true);
        $mock->shouldReceive('get')->with('ai.max_context_tokens')->andReturn(5000);
        $mock->shouldReceive('get')->with('ai.system_prompt')->andReturn('Be helpful');
        $mock->shouldReceive('get')->with('ai.openai_api_key')->andReturn(null);
        $mock->shouldReceive('get')->with('ai.deepseek_client_secret')->andReturn(null);
        $mock->shouldReceive('get')->with('ai.gigachat_client_secret')->andReturn(null);
        $mock->shouldReceive('get')->with('ai.openai_model')->andReturn(null);
        $mock->shouldReceive('get')->with('ai.deepseek_model')->andReturn(null);
        $mock->shouldReceive('get')->with('ai.gigachat_model')->andReturn(null);

        $component = new AiAssistantPage();
        $component->mount($mock);

        $this->assertTrue($component->ai_enabled);
        $this->assertSame('deepseek', $component->default_provider);
        $this->assertTrue($component->auto_reply);
        $this->assertSame(5000, $component->max_context_tokens);
        $this->assertSame('Be helpful', $component->system_prompt);
    }

    public function test_mount_uses_defaults_when_settings_return_null(): void
    {
        /** @var \Mockery\MockInterface&SettingsService $mock */
        $mock = Mockery::mock(SettingsService::class);
        $mock->shouldReceive('get')->with('ai.enabled')->andReturn(null);
        $mock->shouldReceive('get')->with('ai.default_provider')->andReturn(null);
        $mock->shouldReceive('get')->with('ai.auto_reply')->andReturn(null);
        $mock->shouldReceive('get')->with('ai.max_context_tokens')->andReturn(null);
        $mock->shouldReceive('get')->with('ai.system_prompt')->andReturn(null);
        $mock->shouldReceive('get')->with('ai.openai_api_key')->andReturn(null);
        $mock->shouldReceive('get')->with('ai.deepseek_client_secret')->andReturn(null);
        $mock->shouldReceive('get')->with('ai.gigachat_client_secret')->andReturn(null);
        $mock->shouldReceive('get')->with('ai.openai_model')->andReturn(null);
        $mock->shouldReceive('get')->with('ai.deepseek_model')->andReturn(null);
        $mock->shouldReceive('get')->with('ai.gigachat_model')->andReturn(null);

        $component = new AiAssistantPage();
        $component->mount($mock);

        $this->assertFalse($component->ai_enabled);
        $this->assertSame('openai', $component->default_provider);
        $this->assertFalse($component->auto_reply);
        $this->assertSame(3000, $component->max_context_tokens);
        $this->assertSame('', $component->system_prompt);
    }

    // ── save ─────────────────────────────────────────────────────────────────────

    public function test_save_persists_all_fields(): void
    {
        /** @var \Mockery\MockInterface&SettingsService $mock */
        $mock = Mockery::mock(SettingsService::class);
        $mock->shouldReceive('get')->andReturn(null);
        $mock->shouldReceive('set')->with('ai.enabled', false)->once();
        $mock->shouldReceive('set')->with('ai.default_provider', 'gigachat')->once();
        $mock->shouldReceive('set')->with('ai.auto_reply', true)->once();
        $mock->shouldReceive('set')->with('ai.max_context_tokens', 2000)->once();
        $mock->shouldReceive('set')->with('ai.system_prompt', 'Be concise')->once();

        $component = new AiAssistantPage();
        $component->mount($mock);
        $component->default_provider = 'gigachat';
        $component->auto_reply = true;
        $component->max_context_tokens = 2000;
        $component->system_prompt = 'Be concise';
        $component->save($mock);

        $this->assertTrue($component->saved);
        $this->assertEmpty($component->formErrors);
    }

    public function test_save_rejects_invalid_provider(): void
    {
        /** @var \Mockery\MockInterface&SettingsService $mock */
        $mock = Mockery::mock(SettingsService::class);
        $mock->shouldReceive('get')->andReturn(null);
        $mock->shouldNotReceive('set');

        $component = new AiAssistantPage();
        $component->mount($mock);
        $component->default_provider = 'anthropic';
        $component->save($mock);

        $this->assertFalse($component->saved);
        $this->assertArrayHasKey('default_provider', $component->formErrors);
    }

    public function test_save_rejects_zero_max_context_tokens(): void
    {
        /** @var \Mockery\MockInterface&SettingsService $mock */
        $mock = Mockery::mock(SettingsService::class);
        $mock->shouldReceive('get')->andReturn(null);
        $mock->shouldNotReceive('set');

        $component = new AiAssistantPage();
        $component->mount($mock);
        $component->max_context_tokens = 0;
        $component->save($mock);

        $this->assertFalse($component->saved);
        $this->assertArrayHasKey('max_context_tokens', $component->formErrors);
    }

    public function test_save_rejects_negative_max_context_tokens(): void
    {
        /** @var \Mockery\MockInterface&SettingsService $mock */
        $mock = Mockery::mock(SettingsService::class);
        $mock->shouldReceive('get')->andReturn(null);
        $mock->shouldNotReceive('set');

        $component = new AiAssistantPage();
        $component->mount($mock);
        $component->max_context_tokens = -100;
        $component->save($mock);

        $this->assertFalse($component->saved);
        $this->assertArrayHasKey('max_context_tokens', $component->formErrors);
    }

    public function test_save_accepts_all_valid_providers(): void
    {
        foreach (['openai', 'deepseek', 'gigachat'] as $provider) {
            /** @var \Mockery\MockInterface&SettingsService $mock */
            $mock = Mockery::mock(SettingsService::class);
            $mock->shouldReceive('get')->andReturn(null);
            $mock->shouldReceive('set')->with(Mockery::any(), Mockery::any());

            $component = new AiAssistantPage();
            $component->mount($mock);
            $component->default_provider = $provider;
            $component->save($mock);

            $this->assertTrue($component->saved, "Provider {$provider} should be valid.");
            Mockery::close();
        }
    }

    // ── cancel ───────────────────────────────────────────────────────────────────

    public function test_cancel_resets_to_stored_values(): void
    {
        /** @var \Mockery\MockInterface&SettingsService $mock */
        $mock = Mockery::mock(SettingsService::class);
        $mock->shouldReceive('get')->with('ai.enabled')->andReturn(true);
        $mock->shouldReceive('get')->with('ai.default_provider')->andReturn('openai');
        $mock->shouldReceive('get')->with('ai.auto_reply')->andReturn(false);
        $mock->shouldReceive('get')->with('ai.max_context_tokens')->andReturn(3000);
        $mock->shouldReceive('get')->with('ai.system_prompt')->andReturn('Original');
        $mock->shouldReceive('get')->with('ai.openai_api_key')->andReturn(null);
        $mock->shouldReceive('get')->with('ai.deepseek_client_secret')->andReturn(null);
        $mock->shouldReceive('get')->with('ai.gigachat_client_secret')->andReturn(null);
        $mock->shouldReceive('get')->with('ai.openai_model')->andReturn(null);
        $mock->shouldReceive('get')->with('ai.deepseek_model')->andReturn(null);
        $mock->shouldReceive('get')->with('ai.gigachat_model')->andReturn(null);

        $component = new AiAssistantPage();
        $component->mount($mock);
        // Simulate in-flight changes
        $component->default_provider = 'gigachat';
        $component->saved = true;
        $component->formErrors = ['default_provider' => 'Error'];
        $component->showAutoReplyWarning = true;

        $component->cancel($mock);

        $this->assertSame('openai', $component->default_provider);
        $this->assertFalse($component->saved);
        $this->assertEmpty($component->formErrors);
        $this->assertFalse($component->showAutoReplyWarning);
    }

    // ── auto-reply confirm flow ───────────────────────────────────────────────────

    public function test_updated_auto_reply_true_shows_warning_and_reverts_toggle(): void
    {
        $component = new AiAssistantPage();
        $component->auto_reply = false;

        $component->updatedAutoReply(true);

        $this->assertFalse($component->auto_reply);
        $this->assertTrue($component->showAutoReplyWarning);
        $this->assertTrue($component->pendingAutoReply);
    }

    public function test_updated_auto_reply_false_clears_warning(): void
    {
        $component = new AiAssistantPage();
        $component->showAutoReplyWarning = true;
        $component->pendingAutoReply = true;

        $component->updatedAutoReply(false);

        $this->assertFalse($component->showAutoReplyWarning);
        $this->assertFalse($component->pendingAutoReply);
    }

    public function test_confirm_auto_reply_enables_and_clears_warning(): void
    {
        $component = new AiAssistantPage();
        $component->auto_reply = false;
        $component->showAutoReplyWarning = true;
        $component->pendingAutoReply = true;

        $component->confirmAutoReply();

        $this->assertTrue($component->auto_reply);
        $this->assertFalse($component->showAutoReplyWarning);
        $this->assertFalse($component->pendingAutoReply);
    }

    public function test_cancel_auto_reply_keeps_disabled_and_clears_warning(): void
    {
        $component = new AiAssistantPage();
        $component->auto_reply = false;
        $component->showAutoReplyWarning = true;
        $component->pendingAutoReply = true;

        $component->cancelAutoReply();

        $this->assertFalse($component->auto_reply);
        $this->assertFalse($component->showAutoReplyWarning);
        $this->assertFalse($component->pendingAutoReply);
    }
}

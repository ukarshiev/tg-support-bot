<?php

namespace Tests\Unit\Livewire\Settings;

use App\Livewire\Settings\IntegrationChannelPage;
use App\Modules\Admin\Services\ChannelStatusService;
use App\Modules\Admin\Services\WebhookRegistrationService;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Unit-level tests for the IntegrationChannelPage Livewire component.
 *
 * Exercises mount(), save(), connect(), and cancel() in isolation using a
 * mocked SettingsService / ChannelStatusService / WebhookRegistrationService
 * — no DB or Livewire rendering required for the core logic assertions.
 */
class IntegrationChannelPageTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    /**
     * SettingsService mock whose get() returns '' for any key by default.
     *
     * @return \Mockery\MockInterface&SettingsService
     */
    private function settingsMock(): SettingsService
    {
        /** @var \Mockery\MockInterface&SettingsService $mock */
        $mock = Mockery::mock(SettingsService::class);
        $mock->shouldReceive('get')->andReturn('');

        return $mock;
    }

    /**
     * @return \Mockery\MockInterface&ChannelStatusService
     */
    private function statusMock(bool $telegramConnected = false, bool $telegramAiConnected = false): ChannelStatusService
    {
        /** @var \Mockery\MockInterface&ChannelStatusService $mock */
        $mock = Mockery::mock(ChannelStatusService::class);
        $mock->shouldReceive('all')->andReturn([
            'telegram' => ['connected' => $telegramConnected, 'label' => 'x'],
            'telegram_ai' => ['connected' => $telegramAiConnected, 'label' => 'x'],
            'vk' => ['connected' => false, 'label' => 'x'],
            'max' => ['connected' => false, 'label' => 'x'],
        ]);

        return $mock;
    }

    // ── Telegram (main bot) tests ─────────────────────────────────────────────

    public function test_mount_loads_fields_and_connection_status(): void
    {
        /** @var \Mockery\MockInterface&SettingsService $settings */
        $settings = Mockery::mock(SettingsService::class);
        $settings->shouldReceive('get')->with('telegram.group_id')->andReturn('-100123');
        $settings->shouldReceive('get')->with('telegram.token')->andReturn('tok');
        $settings->shouldReceive('get')->with('telegram.secret_key')->andReturn('sec');
        $settings->shouldReceive('get')->with('telegram_ai.username')->andReturn('');
        $settings->shouldReceive('get')->with('telegram_ai.token')->andReturn('');
        $settings->shouldReceive('get')->with('telegram_ai.secret')->andReturn('');
        $settings->shouldReceive('get')->with('vk.token')->andReturn('');
        $settings->shouldReceive('get')->with('vk.secret_key')->andReturn('');
        $settings->shouldReceive('get')->with('vk.confirm_code')->andReturn('');
        $settings->shouldReceive('get')->with('max.token')->andReturn('');
        $settings->shouldReceive('get')->with('max.secret_key')->andReturn('');

        $component = new IntegrationChannelPage();
        $component->mount('telegram', $settings, $this->statusMock(true));

        $this->assertSame('telegram', $component->channel);
        $this->assertSame('-100123', $component->telegram_group_id);
        $this->assertSame('tok', $component->telegram_token);
        $this->assertTrue($component->channelConnected);
    }

    public function test_save_telegram_persists_group_id_and_nonempty_secrets(): void
    {
        $settings = $this->settingsMock();
        $settings->shouldReceive('set')->with('telegram.group_id', '-100999')->once();
        $settings->shouldReceive('set')->with('telegram.token', 'newtok')->once();
        $settings->shouldReceive('set')->with('telegram.secret_key', 'newsec')->once();

        $component = new IntegrationChannelPage();
        $component->mount('telegram', $settings, $this->statusMock());
        $component->telegram_group_id = '-100999';
        $component->telegram_token = 'newtok';
        $component->telegram_secret_key = 'newsec';
        $component->save($settings);

        $this->assertTrue($component->saved);
        $this->assertEmpty($component->formErrors);
    }

    public function test_save_telegram_skips_blank_secrets(): void
    {
        $settings = $this->settingsMock();
        $settings->shouldReceive('set')->with('telegram.group_id', '-100')->once();
        $settings->shouldReceive('set')->with('telegram.token', Mockery::any())->never();
        $settings->shouldReceive('set')->with('telegram.secret_key', Mockery::any())->never();

        $component = new IntegrationChannelPage();
        $component->mount('telegram', $settings, $this->statusMock());
        $component->telegram_group_id = '-100';
        $component->telegram_token = '';
        $component->telegram_secret_key = '';
        $component->save($settings);

        $this->assertTrue($component->saved);
    }

    public function test_save_telegram_rejects_too_long_group_id(): void
    {
        $settings = $this->settingsMock();
        $settings->shouldNotReceive('set');

        $component = new IntegrationChannelPage();
        $component->mount('telegram', $settings, $this->statusMock());
        $component->telegram_group_id = str_repeat('1', 51);
        $component->save($settings);

        $this->assertFalse($component->saved);
        $this->assertArrayHasKey('telegram_group_id', $component->formErrors);
    }

    // ── Telegram AI bot tests ─────────────────────────────────────────────────

    public function test_mount_telegram_ai_channel_sets_channel_slug(): void
    {
        $settings = $this->settingsMock();

        $component = new IntegrationChannelPage();
        $component->mount('telegram_ai', $settings, $this->statusMock(false, true));

        $this->assertSame('telegram_ai', $component->channel);
        $this->assertTrue($component->channelConnected);
    }

    public function test_save_telegram_ai_persists_id_username_and_nonempty_secrets(): void
    {
        $settings = $this->settingsMock();
        $settings->shouldReceive('set')->with('telegram_ai.username', '@ai_bot')->once();
        $settings->shouldReceive('set')->with('telegram_ai.token', 'ai-tok')->once();
        $settings->shouldReceive('set')->with('telegram_ai.secret', 'ai-sec')->once();

        $component = new IntegrationChannelPage();
        $component->mount('telegram_ai', $settings, $this->statusMock());
        $component->telegram_ai_username = '@ai_bot';
        $component->telegram_ai_token = 'ai-tok';
        $component->telegram_ai_secret = 'ai-sec';
        $component->save($settings);

        $this->assertTrue($component->saved);
        $this->assertEmpty($component->formErrors);
    }

    public function test_save_telegram_ai_skips_blank_secrets(): void
    {
        $settings = $this->settingsMock();
        $settings->shouldReceive('set')->with('telegram_ai.username', Mockery::type('string'))->once();
        $settings->shouldReceive('set')->with('telegram_ai.token', Mockery::any())->never();
        $settings->shouldReceive('set')->with('telegram_ai.secret', Mockery::any())->never();

        $component = new IntegrationChannelPage();
        $component->mount('telegram_ai', $settings, $this->statusMock());
        $component->telegram_ai_token = '';
        $component->telegram_ai_secret = '';
        $component->save($settings);

        $this->assertTrue($component->saved);
    }

    public function test_connect_telegram_ai_saves_but_does_not_call_webhook_service(): void
    {
        $settings = $this->settingsMock();
        $settings->shouldReceive('get')->with('telegram_ai.token')->andReturn('');
        $settings->shouldReceive('set')->with('telegram_ai.username', Mockery::type('string'))->once();
        $settings->shouldReceive('set')->with('telegram_ai.token', 'ai-tok')->once();

        /** @var \Mockery\MockInterface&WebhookRegistrationService $webhook */
        $webhook = Mockery::mock(WebhookRegistrationService::class);
        $webhook->shouldReceive('verifyTelegram')->with('ai-tok', \Mockery::any())->once()->andReturn(['success' => true, 'message' => 'OK']);
        $webhook->shouldNotReceive('registerTelegram');
        $webhook->shouldNotReceive('registerVk');
        $webhook->shouldNotReceive('registerMax');

        $component = new IntegrationChannelPage();
        $component->mount('telegram_ai', $settings, $this->statusMock());
        $component->telegram_ai_token = 'ai-tok';
        $component->connect($settings, $webhook);

        $this->assertTrue($component->saved);
        $this->assertTrue($component->webhookSuccess);
        $this->assertNotNull($component->webhookMessage);
    }

    // ── VK tests ──────────────────────────────────────────────────────────────

    public function test_save_vk_persists_only_nonempty_fields(): void
    {
        $settings = $this->settingsMock();
        $settings->shouldReceive('set')->with('vk.token', 'vktok')->once();
        $settings->shouldReceive('set')->with('vk.secret_key', Mockery::any())->never();
        $settings->shouldReceive('set')->with('vk.confirm_code', 'cc')->once();

        $component = new IntegrationChannelPage();
        $component->mount('vk', $settings, $this->statusMock());
        $component->vk_token = 'vktok';
        $component->vk_secret_key = '';
        $component->vk_confirm_code = 'cc';
        $component->save($settings);

        $this->assertTrue($component->saved);
    }

    // ── connect() tests ───────────────────────────────────────────────────────

    public function test_connect_saves_then_registers_webhook_for_telegram(): void
    {
        $settings = $this->settingsMock();
        $settings->shouldReceive('get')->with('telegram.token')->andReturn('');
        $settings->shouldReceive('set')->with('telegram.group_id', '-100')->once();
        $settings->shouldReceive('set')->with('telegram.token', 'tok123')->once();

        /** @var \Mockery\MockInterface&WebhookRegistrationService $webhook */
        $webhook = Mockery::mock(WebhookRegistrationService::class);
        $webhook->shouldReceive('verifyTelegram')->with('tok123', \Mockery::any())->once()->andReturn(['success' => true, 'message' => 'Verified']);
        $webhook->shouldReceive('registerTelegram')->with()->once()->andReturn(['success' => true, 'message' => 'OK']);

        $component = new IntegrationChannelPage();
        $component->mount('telegram', $settings, $this->statusMock());
        $component->telegram_group_id = '-100';
        $component->telegram_token = 'tok123';
        $component->connect($settings, $webhook);

        $this->assertTrue($component->saved);
        $this->assertTrue($component->webhookSuccess);
        $this->assertSame('OK', $component->webhookMessage);
    }

    public function test_connect_does_not_verify_or_register_webhook_when_validation_fails(): void
    {
        $settings = $this->settingsMock();
        $settings->shouldNotReceive('set');

        /** @var \Mockery\MockInterface&WebhookRegistrationService $webhook */
        $webhook = Mockery::mock(WebhookRegistrationService::class);
        $webhook->shouldNotReceive('verifyTelegram');
        $webhook->shouldNotReceive('registerTelegram');

        $component = new IntegrationChannelPage();
        $component->mount('telegram', $settings, $this->statusMock());
        $component->telegram_group_id = str_repeat('1', 51);
        $component->connect($settings, $webhook);

        $this->assertFalse($component->saved);
        $this->assertNull($component->webhookMessage);
    }

    public function test_connect_does_not_save_when_verification_fails(): void
    {
        $settings = $this->settingsMock();
        $settings->shouldNotReceive('set');

        /** @var \Mockery\MockInterface&WebhookRegistrationService $webhook */
        $webhook = Mockery::mock(WebhookRegistrationService::class);
        $webhook->shouldReceive('verifyTelegram')->with('bad_tok', \Mockery::any())->once()->andReturn(['success' => false, 'message' => 'Неверный токен Telegram.']);
        $webhook->shouldNotReceive('registerTelegram');

        $component = new IntegrationChannelPage();
        $component->mount('telegram', $settings, $this->statusMock());
        $component->telegram_group_id = '-100';
        $component->telegram_token = 'bad_tok';
        $component->connect($settings, $webhook);

        $this->assertFalse($component->saved);
        $this->assertFalse($component->webhookSuccess);
        $this->assertSame('Неверный токен Telegram.', $component->webhookMessage);
    }

    public function test_connect_uses_stored_token_when_form_field_is_blank(): void
    {
        // Build mock from scratch so specific expectations take priority over catch-all.
        /** @var \Mockery\MockInterface&SettingsService $settings */
        $settings = Mockery::mock(SettingsService::class);
        // loadFields() reads all known keys; resolve reads telegram.token again for fallback.
        $settings->shouldReceive('get')->with('telegram.token')->andReturn('stored_tok');
        $settings->shouldReceive('get')->andReturn('');  // catch-all for all other keys
        $settings->shouldReceive('set')->with('telegram.group_id', '-100')->once();

        /** @var \Mockery\MockInterface&WebhookRegistrationService $webhook */
        $webhook = Mockery::mock(WebhookRegistrationService::class);
        // verify must be called with the stored token, not the blank form field
        $webhook->shouldReceive('verifyTelegram')->with('stored_tok', \Mockery::any())->once()->andReturn(['success' => true, 'message' => 'OK']);
        $webhook->shouldReceive('registerTelegram')->once()->andReturn(['success' => true, 'message' => 'Registered']);

        $component = new IntegrationChannelPage();
        $component->mount('telegram', $settings, $this->statusMock());
        $component->telegram_group_id = '-100';
        $component->telegram_token = '';   // blank — must fall back to stored
        $component->connect($settings, $webhook);

        $this->assertTrue($component->saved);
        $this->assertTrue($component->webhookSuccess);
    }

    public function test_connect_sets_error_when_no_token_available(): void
    {
        // Build mock from scratch so specific expectations take priority over catch-all.
        /** @var \Mockery\MockInterface&SettingsService $settings */
        $settings = Mockery::mock(SettingsService::class);
        $settings->shouldReceive('get')->with('telegram.token')->andReturn('');
        $settings->shouldReceive('get')->andReturn('');  // catch-all for all other keys
        $settings->shouldNotReceive('set');

        /** @var \Mockery\MockInterface&WebhookRegistrationService $webhook */
        $webhook = Mockery::mock(WebhookRegistrationService::class);
        $webhook->shouldNotReceive('verifyTelegram');
        $webhook->shouldNotReceive('registerTelegram');

        $component = new IntegrationChannelPage();
        $component->mount('telegram', $settings, $this->statusMock());
        $component->telegram_group_id = '-100';
        $component->telegram_token = '';   // blank — no stored token either
        $component->connect($settings, $webhook);

        $this->assertFalse($component->saved);
        $this->assertFalse($component->webhookSuccess);
        $this->assertStringContainsString('Введите токен', (string) $component->webhookMessage);
    }

    // ── cancel() test ─────────────────────────────────────────────────────────

    public function test_cancel_resets_fields_from_settings(): void
    {
        /** @var \Mockery\MockInterface&SettingsService $settings */
        $settings = Mockery::mock(SettingsService::class);
        $settings->shouldReceive('get')->with('telegram.group_id')->andReturn('-100stored');
        $settings->shouldReceive('get')->with('telegram.token')->andReturn('');
        $settings->shouldReceive('get')->with('telegram.secret_key')->andReturn('');
        $settings->shouldReceive('get')->with('telegram_ai.username')->andReturn('');
        $settings->shouldReceive('get')->with('telegram_ai.token')->andReturn('');
        $settings->shouldReceive('get')->with('telegram_ai.secret')->andReturn('');
        $settings->shouldReceive('get')->with('vk.token')->andReturn('');
        $settings->shouldReceive('get')->with('vk.secret_key')->andReturn('');
        $settings->shouldReceive('get')->with('vk.confirm_code')->andReturn('');
        $settings->shouldReceive('get')->with('max.token')->andReturn('');
        $settings->shouldReceive('get')->with('max.secret_key')->andReturn('');

        $component = new IntegrationChannelPage();
        $component->mount('telegram', $settings, $this->statusMock());
        $component->telegram_group_id = 'changed';
        $component->saved = true;
        $component->cancel($settings);

        $this->assertSame('-100stored', $component->telegram_group_id);
        $this->assertFalse($component->saved);
        $this->assertEmpty($component->formErrors);
    }
}

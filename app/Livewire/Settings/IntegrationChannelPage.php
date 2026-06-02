<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Modules\Admin\Services\ChannelStatusService;
use App\Modules\Admin\Services\WebhookRegistrationService;
use App\Services\Settings\SettingsService;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Per-channel integration configuration page.
 *
 * Handles config forms for Telegram, VK, and MAX channels.
 * Reads/writes via SettingsService (secrets are stored encrypted).
 *
 * The «Подключить» button saves config AND attempts webhook registration in
 * a single action (connect()). A separate cancel() resets the form.
 *
 * Route: /admin/settings/integrations/{channel} where channel ∈ {telegram, vk, max}
 *
 * Access: authenticated users via route middleware.
 * Layout: custom dark-sidebar admin layout (layouts.admin-settings).
 */
#[Layout('layouts.admin-settings')]
class IntegrationChannelPage extends Component
{
    /** @var string The current channel slug (telegram|vk|max) */
    public string $channel = 'telegram';

    // ── Telegram fields ───────────────────────────────────────────────────────

    /** @var string|null */
    public ?string $telegram_group_id = null;

    /** @var string|null */
    public ?string $telegram_token = null;

    /** @var string|null */
    public ?string $telegram_secret_key = null;

    /** @var string|null Numeric bot ID (non-secret) */
    public ?string $telegram_bot_id = null;

    /** @var string|null Topic name template */
    public ?string $telegram_template_topic_name = null;

    // ── Telegram AI-bot fields ────────────────────────────────────────────────

    /** @var string|null AI-bot token (secret — never pre-filled) */
    public ?string $telegram_ai_token = null;

    /** @var string|null AI-bot webhook secret (secret — never pre-filled) */
    public ?string $telegram_ai_secret = null;

    /** @var string|null AI-bot numeric ID */
    public ?string $telegram_ai_id = null;

    /** @var string|null AI-bot @username */
    public ?string $telegram_ai_username = null;

    // ── VK fields ─────────────────────────────────────────────────────────────

    /** @var string|null */
    public ?string $vk_token = null;

    /** @var string|null */
    public ?string $vk_secret_key = null;

    /** @var string|null */
    public ?string $vk_confirm_code = null;

    // ── MAX fields ────────────────────────────────────────────────────────────

    /** @var string|null */
    public ?string $max_token = null;

    /** @var string|null */
    public ?string $max_secret_key = null;

    // ── State ─────────────────────────────────────────────────────────────────

    /** @var bool Config was persisted successfully in this request */
    public bool $saved = false;

    /** @var bool */
    public bool $channelConnected = false;

    /** @var array<string, string> */
    public array $formErrors = [];

    /** @var string|null Webhook registration result message */
    public ?string $webhookMessage = null;

    /** @var bool Webhook registration succeeded */
    public bool $webhookSuccess = false;

    /**
     * Mount the component with the channel slug from the route.
     */
    public function mount(string $channel, SettingsService $settings, ChannelStatusService $channelStatus): void
    {
        $this->channel = $channel;
        $this->loadFields($settings);

        $statuses = $channelStatus->all();
        $this->channelConnected = $statuses[$channel]['connected'] ?? false;
    }

    /**
     * Save config then attempt webhook registration — the «Подключить» action.
     *
     * Saves first; on success immediately registers the webhook so the user
     * sees a combined result without a second click.
     */
    public function connect(SettingsService $settings, WebhookRegistrationService $webhook): void
    {
        $this->formErrors = [];
        $this->saved = false;
        $this->webhookMessage = null;
        $this->webhookSuccess = false;

        match ($this->channel) {
            'telegram' => $this->saveTelegram($settings),
            'vk' => $this->saveVk($settings),
            'max' => $this->saveMax($settings),
            default => $this->formErrors['channel'] = 'Неизвестный канал.',
        };

        if (! $this->saved) {
            return;
        }

        // Attempt webhook registration after a successful save.
        $result = match ($this->channel) {
            'telegram' => $webhook->registerTelegram(),
            'vk' => $webhook->registerVk(),
            'max' => $webhook->registerMax(),
            default => ['success' => false, 'message' => 'Неизвестный канал.'],
        };

        $this->webhookSuccess = $result['success'];
        $this->webhookMessage = $result['message'];
    }

    /**
     * Save the current channel's form values via SettingsService.
     *
     * Kept as a standalone method so unit tests can call it directly without
     * triggering the webhook step.
     */
    public function save(SettingsService $settings): void
    {
        $this->formErrors = [];
        $this->saved = false;

        match ($this->channel) {
            'telegram' => $this->saveTelegram($settings),
            'vk' => $this->saveVk($settings),
            'max' => $this->saveMax($settings),
            default => $this->formErrors['channel'] = 'Неизвестный канал.',
        };
    }

    /**
     * Register the webhook for the current channel (standalone action, kept
     * for backward-compatibility with existing tests).
     */
    public function registerWebhook(WebhookRegistrationService $webhook): void
    {
        $this->webhookMessage = null;
        $this->webhookSuccess = false;

        $result = match ($this->channel) {
            'telegram' => $webhook->registerTelegram(),
            'vk' => $webhook->registerVk(),
            'max' => $webhook->registerMax(),
            default => ['success' => false, 'message' => 'Неизвестный канал.'],
        };

        $this->webhookSuccess = $result['success'];
        $this->webhookMessage = $result['message'];
    }

    /**
     * Reset the form to the currently stored values.
     */
    public function cancel(SettingsService $settings): void
    {
        $this->formErrors = [];
        $this->saved = false;
        $this->webhookMessage = null;
        $this->loadFields($settings);
    }

    /**
     * Render the component view.
     *
     * @return \Illuminate\View\View
     */
    public function render(): \Illuminate\View\View
    {
        return view('livewire.settings.integration-channel-page');
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Populate all channel fields from SettingsService.
     */
    private function loadFields(SettingsService $settings): void
    {
        $this->telegram_group_id = (string) ($settings->get('telegram.group_id') ?? '');
        $this->telegram_token = (string) ($settings->get('telegram.token') ?? '');
        $this->telegram_secret_key = (string) ($settings->get('telegram.secret_key') ?? '');
        $this->telegram_bot_id = (string) ($settings->get('telegram.bot_id') ?? '');
        $this->telegram_template_topic_name = (string) ($settings->get('telegram.template_topic_name') ?? '');

        // AI-bot: non-secret fields pre-filled; secret fields intentionally null (never pre-filled)
        $this->telegram_ai_id = (string) ($settings->get('telegram_ai.id') ?? '');
        $this->telegram_ai_username = (string) ($settings->get('telegram_ai.username') ?? '');
        $this->telegram_ai_token = null;
        $this->telegram_ai_secret = null;

        $this->vk_token = (string) ($settings->get('vk.token') ?? '');
        $this->vk_secret_key = (string) ($settings->get('vk.secret_key') ?? '');
        $this->vk_confirm_code = (string) ($settings->get('vk.confirm_code') ?? '');

        $this->max_token = (string) ($settings->get('max.token') ?? '');
        $this->max_secret_key = (string) ($settings->get('max.secret_key') ?? '');
    }

    /**
     * Validate and save Telegram channel settings.
     */
    private function saveTelegram(SettingsService $settings): void
    {
        if (strlen((string) $this->telegram_group_id) > 50) {
            $this->formErrors['telegram_group_id'] = 'Максимальная длина — 50 символов.';
        }

        if (! empty($this->formErrors)) {
            return;
        }

        $settings->set('telegram.group_id', $this->telegram_group_id ?? '');
        $settings->set('telegram.bot_id', (int) $this->telegram_bot_id);
        $settings->set('telegram.template_topic_name', $this->telegram_template_topic_name ?? '');

        // Save each secret only when non-empty (do not overwrite existing secrets with blank)
        if ($this->telegram_token !== '' && $this->telegram_token !== null) {
            $settings->set('telegram.token', $this->telegram_token);
        }
        if ($this->telegram_secret_key !== '' && $this->telegram_secret_key !== null) {
            $settings->set('telegram.secret_key', $this->telegram_secret_key);
        }

        // AI-bot fields
        $settings->set('telegram_ai.id', (int) $this->telegram_ai_id);
        $settings->set('telegram_ai.username', $this->telegram_ai_username ?? '');

        if ($this->telegram_ai_token !== '' && $this->telegram_ai_token !== null) {
            $settings->set('telegram_ai.token', $this->telegram_ai_token);
        }
        if ($this->telegram_ai_secret !== '' && $this->telegram_ai_secret !== null) {
            $settings->set('telegram_ai.secret', $this->telegram_ai_secret);
        }

        $this->saved = true;
    }

    /**
     * Validate and save VK channel settings.
     */
    private function saveVk(SettingsService $settings): void
    {
        if (! empty($this->formErrors)) {
            return;
        }

        if ($this->vk_token !== '') {
            $settings->set('vk.token', $this->vk_token);
        }
        if ($this->vk_secret_key !== '') {
            $settings->set('vk.secret_key', $this->vk_secret_key);
        }
        if ($this->vk_confirm_code !== '') {
            $settings->set('vk.confirm_code', $this->vk_confirm_code);
        }

        $this->saved = true;
    }

    /**
     * Validate and save MAX channel settings.
     */
    private function saveMax(SettingsService $settings): void
    {
        if (! empty($this->formErrors)) {
            return;
        }

        if ($this->max_token !== '') {
            $settings->set('max.token', $this->max_token);
        }
        if ($this->max_secret_key !== '') {
            $settings->set('max.secret_key', $this->max_secret_key);
        }

        $this->saved = true;
    }
}

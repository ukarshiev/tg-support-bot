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
 * Handles config forms for Telegram, Telegram AI bot, VK, and MAX channels.
 * Reads/writes via SettingsService (secrets are stored encrypted).
 *
 * The primary «Сохранить» button runs a verify-before-save flow via connect():
 *   1. Validate form fields (formErrors). On error → stop.
 *   2. Resolve the token for verification: the entered form value if non-empty,
 *      otherwise the currently stored token (so editing without re-entering the
 *      secret still works). If no token is available at all → set an error and stop.
 *   3. Call the matching WebhookRegistrationService::verifyX($token). On failure →
 *      set $webhookMessage / $webhookSuccess = false and return without saving.
 *   4. On success → persist via the existing saveX() path, register the webhook
 *      (for telegram|vk|max), and show the success notice.
 *
 * For telegram_ai the flow is identical except there is no webhook registration
 * step (webhook is set via `php artisan ai-bot:set-webhook`).
 *
 * save() (settings-only, no verify/webhook) remains available for tests that
 * call it directly.
 *
 * Route: /admin/settings/integrations/{channel}
 *        where channel ∈ {telegram, telegram_ai, vk, max}
 *
 * Access: authenticated users via route middleware.
 * Layout: custom dark-sidebar admin layout (layouts.admin-settings).
 */
#[Layout('layouts.admin-settings')]
class IntegrationChannelPage extends Component
{
    /** @var string The current channel slug (telegram|telegram_ai|vk|max) */
    public string $channel = 'telegram';

    // ── Telegram (main bot) fields ────────────────────────────────────────────

    /** @var string|null */
    public ?string $telegram_group_id = null;

    /** @var string|null */
    public ?string $telegram_token = null;

    /** @var string|null */
    public ?string $telegram_secret_key = null;

    // ── Telegram AI bot fields ────────────────────────────────────────────────

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
     * Verify credentials, save config, then register the webhook — the «Сохранить» action.
     *
     * Step 1: run field validation (saveTelegram/Vk/Max validation rules).
     * Step 2: resolve the token to verify (entered value or stored fallback).
     * Step 3: call verifyX($token). On failure → set error notice, return without saving.
     * Step 4: persist via saveX(), register webhook (telegram|vk|max), show success notice.
     *
     * For telegram_ai: same flow (step 1-3 using verifyTelegram) but no webhook registration.
     */
    public function connect(SettingsService $settings, WebhookRegistrationService $webhook): void
    {
        $this->formErrors = [];
        $this->saved = false;
        $this->webhookMessage = null;
        $this->webhookSuccess = false;

        // Step 1: validate fields (uses saveTelegram/Ai/Vk/Max internally via a dry-run
        // approach — we call a validation-only guard before committing anything).
        $validationError = $this->validateFields();
        if ($validationError !== null) {
            return;
        }

        // Step 2: resolve the token for the platform check.
        [$tokenToVerify, $tokenError] = $this->resolveVerificationToken($settings);
        if ($tokenError !== null) {
            $this->webhookMessage = $tokenError;
            $this->webhookSuccess = false;

            return;
        }

        // Telegram group to verify the bot's access to (entered value, else stored).
        $telegramGroupId = ($this->telegram_group_id !== null && $this->telegram_group_id !== '')
            ? $this->telegram_group_id
            : (string) ($settings->get('telegram.group_id') ?? '');

        // Step 3: verify the token (and, for Telegram, the group access) against the platform API.
        $verifyResult = match ($this->channel) {
            'telegram' => $webhook->verifyTelegram($tokenToVerify, $telegramGroupId),
            'telegram_ai' => $webhook->verifyTelegram($tokenToVerify, $telegramGroupId),
            'vk' => $webhook->verifyVk($tokenToVerify),
            'max' => $webhook->verifyMax($tokenToVerify),
            default => ['success' => false, 'message' => 'Неизвестный канал.'],
        };

        if (! $verifyResult['success']) {
            $this->webhookMessage = $verifyResult['message'];
            $this->webhookSuccess = false;

            return;
        }

        // Step 4: persist settings.
        match ($this->channel) {
            'telegram' => $this->saveTelegram($settings),
            'telegram_ai' => $this->saveTelegramAi($settings),
            'vk' => $this->saveVk($settings),
            'max' => $this->saveMax($settings),
            default => $this->formErrors['channel'] = 'Неизвестный канал.',
        };

        if (! $this->saved) {
            return;
        }

        // telegram_ai: no webhook registration via UI — artisan command only.
        if ($this->channel === 'telegram_ai') {
            $this->webhookSuccess = true;
            $this->webhookMessage = 'Настройки AI-бота сохранены.';

            return;
        }

        // Register the webhook after a successful save.
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
            'telegram_ai' => $this->saveTelegramAi($settings),
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
     * Run field-level validation for the current channel without persisting anything.
     *
     * Returns null when valid; sets $this->formErrors and returns the error key when
     * validation fails so connect() can abort before the verify step.
     *
     * @return string|null The first failing error key, or null on success.
     */
    private function validateFields(): ?string
    {
        if ($this->channel === 'telegram') {
            if (strlen((string) $this->telegram_group_id) > 50) {
                $this->formErrors['telegram_group_id'] = 'Максимальная длина — 50 символов.';

                return 'telegram_group_id';
            }
        }

        return null;
    }

    /**
     * Resolve the token to use for platform verification.
     *
     * Prefers the value the user typed in the form; falls back to the stored token when
     * the field is blank (edit-without-re-entering-secret use case).
     *
     * @param SettingsService $settings
     *
     * @return array{0: string, 1: string|null} [tokenString, errorMessage|null]
     */
    private function resolveVerificationToken(SettingsService $settings): array
    {
        $tokenField = match ($this->channel) {
            'telegram' => $this->telegram_token,
            'telegram_ai' => $this->telegram_ai_token,
            'vk' => $this->vk_token,
            'max' => $this->max_token,
            default => null,
        };

        $settingsKey = match ($this->channel) {
            'telegram' => 'telegram.token',
            'telegram_ai' => 'telegram_ai.token',
            'vk' => 'vk.token',
            'max' => 'max.token',
            default => null,
        };

        // Use the entered value when non-empty.
        if ($tokenField !== null && $tokenField !== '') {
            return [$tokenField, null];
        }

        // Fall back to the stored token.
        if ($settingsKey !== null) {
            $stored = (string) ($settings->get($settingsKey) ?? '');
            if ($stored !== '') {
                return [$stored, null];
            }
        }

        // No token available at all.
        $label = match ($this->channel) {
            'telegram' => 'Telegram',
            'telegram_ai' => 'AI-бота',
            'vk' => 'VK',
            'max' => 'MAX',
            default => 'платформы',
        };

        return ['', 'Введите токен ' . $label . ' для проверки подключения.'];
    }

    /**
     * Populate all channel fields from SettingsService.
     */
    private function loadFields(SettingsService $settings): void
    {
        $this->telegram_group_id = (string) ($settings->get('telegram.group_id') ?? '');
        $this->telegram_token = (string) ($settings->get('telegram.token') ?? '');
        $this->telegram_secret_key = (string) ($settings->get('telegram.secret_key') ?? '');

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
     * Validate and save Telegram main bot channel settings.
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

        // Save each secret only when non-empty (do not overwrite existing secrets with blank)
        if ($this->telegram_token !== '' && $this->telegram_token !== null) {
            $settings->set('telegram.token', $this->telegram_token);
        }
        if ($this->telegram_secret_key !== '' && $this->telegram_secret_key !== null) {
            $settings->set('telegram.secret_key', $this->telegram_secret_key);
        }

        $this->saved = true;
    }

    /**
     * Validate and save Telegram AI bot channel settings.
     */
    private function saveTelegramAi(SettingsService $settings): void
    {
        if (! empty($this->formErrors)) {
            return;
        }

        $settings->set('telegram_ai.id', (int) $this->telegram_ai_id);
        $settings->set('telegram_ai.username', $this->telegram_ai_username ?? '');

        // Save secrets only when non-empty (do not overwrite existing secrets with blank)
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

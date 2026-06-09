<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Modules\Admin\Services\ChannelStatusService;
use App\Services\Settings\SettingsService;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * AI assistant settings page.
 *
 * Manages the master AI toggle, provider selection, auto-reply mode,
 * context token limit, and system prompt. All values are persisted via
 * SettingsService (DB → config() fallback, cache-backed).
 *
 * Access: authenticated admin only (enforced in route middleware).
 * Layout: custom dark-sidebar admin layout (layouts.admin-settings).
 *
 * Route: GET /admin/settings/ai
 */
#[Layout('layouts.admin-settings')]
class AiAssistantPage extends Component
{
    /** @var bool Master AI on/off toggle */
    public bool $ai_enabled = false;

    /** @var string Active provider: openai|deepseek|gigachat */
    public string $default_provider = 'openai';

    /** @var bool Auto-reply mode (true = auto, false = draft) */
    public bool $auto_reply = false;

    /** @var int Rate limit: requests per minute */
    public int $rate_limit_per_minute = 60;

    /** @var int Rate limit: requests per hour */
    public int $rate_limit_per_hour = 1000;

    /** @var string AI timeout setting (seconds or false) */
    public string $disable_timeout = '';

    /** @var string System prompt text */
    public string $system_prompt = '';

    /** @var array<string, bool> Whether each provider has its credentials configured */
    public array $providerConfigured = [];

    /** @var array<string, string> Configured model name per provider */
    public array $providerModels = [];

    /** @var bool Whether the «Бот AI помощника» integration (telegram_ai.token) is configured */
    public bool $aiBotConnected = false;

    /** @var bool Show auto-reply warning/confirm */
    public bool $showAutoReplyWarning = false;

    /** @var bool Pending auto-reply value awaiting confirmation */
    public bool $pendingAutoReply = false;

    /** @var bool Success banner visible */
    public bool $saved = false;

    /** @var array<string, string> Validation errors keyed by field name */
    public array $formErrors = [];

    /**
     * Load current values from SettingsService on mount.
     */
    public function mount(SettingsService $settings): void
    {
        $this->loadFields($settings);
    }

    /**
     * Called when the master AI toggle changes via wire:model.live.
     *
     * The «Включить AI помощника» switch persists immediately — it does not
     * wait for the «Сохранить» button. All other fields still require save.
     *
     * Guard: the assistant cannot be enabled until the «Бот AI помощника»
     * integration (telegram_ai.token) is configured — otherwise AI replies
     * cannot be posted to the supergroup. Disabling is always allowed.
     *
     * @param bool $value New toggle state
     */
    public function updatedAiEnabled(bool $value): void
    {
        if ($value && ! $this->aiBotConnected) {
            // Revert — the AI bot integration must be set up first.
            $this->ai_enabled = false;

            return;
        }

        app(SettingsService::class)->set('ai.enabled', $value);
    }

    /**
     * Called when the auto-reply toggle changes via wire:model.live.
     *
     * When enabling auto-reply, show a confirmation warning instead of
     * saving immediately (BR-002: warn before enabling auto-reply).
     */
    public function updatedAutoReply(bool $value): void
    {
        if ($value) {
            // Revert the toggle — wait for user confirmation
            $this->auto_reply = false;
            $this->pendingAutoReply = true;
            $this->showAutoReplyWarning = true;
        } else {
            $this->showAutoReplyWarning = false;
            $this->pendingAutoReply = false;
        }
    }

    /**
     * Confirm auto-reply activation after the user accepted the warning.
     */
    public function confirmAutoReply(): void
    {
        $this->auto_reply = true;
        $this->pendingAutoReply = false;
        $this->showAutoReplyWarning = false;
    }

    /**
     * Cancel auto-reply activation — keep draft mode.
     */
    public function cancelAutoReply(): void
    {
        $this->auto_reply = false;
        $this->pendingAutoReply = false;
        $this->showAutoReplyWarning = false;
    }

    /**
     * Save all AI settings via SettingsService.
     */
    public function save(SettingsService $settings): void
    {
        $this->formErrors = [];
        $this->saved = false;

        // Validation — only the (visible) detail settings matter when AI is enabled.
        if ($this->ai_enabled) {
            if (! in_array($this->default_provider, ['openai', 'deepseek', 'gigachat'], true)) {
                $this->formErrors['default_provider'] = 'Выберите допустимый провайдер.';
            } elseif (! $this->providerHasAccess($settings, $this->default_provider)) {
                $this->formErrors['default_provider'] = 'У выбранного провайдера не указаны доступы.';
            }

            if ($this->rate_limit_per_minute < 1) {
                $this->formErrors['rate_limit_per_minute'] = 'Лимит запросов в минуту должен быть положительным числом.';
            }

            if ($this->rate_limit_per_hour < 1) {
                $this->formErrors['rate_limit_per_hour'] = 'Лимит запросов в час должен быть положительным числом.';
            }
        }

        if (! empty($this->formErrors)) {
            return;
        }

        $settings->set('ai.enabled', $this->ai_enabled);
        $settings->set('ai.default_provider', $this->default_provider);
        $settings->set('ai.auto_reply', $this->auto_reply);
        $settings->set('ai.rate_limit.requests_per_minute', $this->rate_limit_per_minute);
        $settings->set('ai.rate_limit.requests_per_hour', $this->rate_limit_per_hour);
        $settings->set('ai.disable_timeout', $this->disable_timeout);
        $settings->set('ai.system_prompt', $this->system_prompt);

        $this->saved = true;
    }

    /**
     * Render the component view.
     *
     * @return \Illuminate\View\View
     */
    public function render(): \Illuminate\View\View
    {
        return view('livewire.settings.ai-assistant-page');
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Populate component properties from SettingsService.
     */
    private function loadFields(SettingsService $settings): void
    {
        $this->ai_enabled = (bool) ($settings->get('ai.enabled') ?? false);
        $this->auto_reply = (bool) ($settings->get('ai.auto_reply') ?? false);
        $this->rate_limit_per_minute = (int) ($settings->get('ai.rate_limit.requests_per_minute') ?? 60);
        $this->rate_limit_per_hour = (int) ($settings->get('ai.rate_limit.requests_per_hour') ?? 1000);
        $this->disable_timeout = (string) ($settings->get('ai.disable_timeout') ?? '');
        $this->system_prompt = (string) ($settings->get('ai.system_prompt') ?? '');

        $this->providerConfigured = [
            'openai' => $this->providerHasAccess($settings, 'openai'),
            'deepseek' => $this->providerHasAccess($settings, 'deepseek'),
            'gigachat' => $this->providerHasAccess($settings, 'gigachat'),
        ];

        // Resolve the active provider. A provider with no configured access must
        // never appear pre-selected: keep the stored choice only when it is
        // configured, otherwise fall back to the first configured provider (or
        // none at all when nothing is set up yet).
        $stored = (string) ($settings->get('ai.default_provider') ?? '');
        $this->default_provider = ($stored !== '' && ($this->providerConfigured[$stored] ?? false))
            ? $stored
            : $this->firstConfiguredProvider();

        $this->providerModels = [
            'openai' => (string) ($settings->get('ai.openai_model') ?? ''),
            'deepseek' => (string) ($settings->get('ai.deepseek_model') ?? ''),
            'gigachat' => (string) ($settings->get('ai.gigachat_model') ?? ''),
        ];

        // The assistant cannot run without a working AI bot (telegram_ai.token):
        // its replies are posted to the supergroup as the AI bot.
        $this->aiBotConnected = app(ChannelStatusService::class)->telegramAi()['connected'];
    }

    /**
     * Whether a provider has its access credentials configured.
     *
     * A provider with no credentials cannot be selected as the active AI
     * provider — the UI shows a «Доступы не указаны» flag instead of the
     * select button, and save() rejects it.
     *
     * @param SettingsService $settings Settings persistence layer
     * @param string          $provider Provider slug: openai|deepseek|gigachat
     *
     * @return bool True when the provider's primary credential is present
     */
    private function providerHasAccess(SettingsService $settings, string $provider): bool
    {
        return match ($provider) {
            'openai' => filled($settings->get('ai.openai_api_key')),
            'deepseek' => filled($settings->get('ai.deepseek_client_secret')),
            'gigachat' => filled($settings->get('ai.gigachat_client_secret')),
            default => false,
        };
    }

    /**
     * First provider (in card order) that has its access configured.
     *
     * Used as the default active selection so an unconfigured provider is
     * never pre-selected. Returns an empty string when no provider is set up.
     *
     * @return string Provider slug, or '' when none is configured
     */
    private function firstConfiguredProvider(): string
    {
        foreach ($this->providerConfigured as $slug => $configured) {
            if ($configured) {
                return $slug;
            }
        }

        return '';
    }
}

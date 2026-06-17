<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Modules\Admin\Services\WebhookRegistrationService;
use App\Services\Settings\SettingsService;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Custom Livewire full-page component for the «Основные» settings screen.
 *
 * Manages:
 *   - telegram.template_topic_name — Telegram forum topic name template
 *   - telegram.group_id            — Telegram supergroup ID for receiving messages
 *
 * Reads and writes via SettingsService (DB → config() fallback, cache-backed).
 * Access: authenticated admin only (enforced in route middleware + mount()).
 * Layout: custom dark-sidebar admin layout (layouts.admin-settings).
 */
#[Layout('layouts.admin-settings')]
class GeneralSettingsPage extends Component
{
    /** @var string|null Telegram forum topic name template */
    public ?string $template_topic_name = null;

    /** @var string|null Telegram supergroup ID for receiving messages (e.g. -100XXXXXXXXXX) */
    public ?string $group_id = null;

    /** @var bool Show success banner */
    public bool $saved = false;

    /** @var array<string, string> */
    public array $formErrors = [];

    /**
     * Load current values from SettingsService on mount.
     */
    public function mount(SettingsService $settings): void
    {
        $this->template_topic_name = (string) ($settings->get('telegram.template_topic_name') ?? '');
        $this->group_id = (string) ($settings->get('telegram.group_id') ?? '');
    }

    /**
     * Save the form values via SettingsService.
     */
    public function save(SettingsService $settings): void
    {
        $this->formErrors = [];
        $this->saved = false;

        // Normalize: a pasted group ID often carries leading/trailing whitespace,
        // which would make getChat fail even for a correct ID.
        $this->group_id = trim((string) ($this->group_id ?? ''));

        // ── Validation ────────────────────────────────────────────────────────
        if (strlen((string) $this->template_topic_name) > 255) {
            $this->formErrors['template_topic_name'] = 'Максимальная длина — 255 символов.';
        }

        // Optional: the Telegram supergroup is an addition. Empty group_id means
        // admin-panel-only (no group mirroring). Validate length only when filled.
        if (strlen((string) $this->group_id) > 50) {
            $this->formErrors['group_id'] = 'Максимальная длина — 50 символов.';
        }

        if (! empty($this->formErrors)) {
            return;
        }

        // ── Verify-before-save for the group ──────────────────────────────────
        // When a group ID is provided, check (1) the Telegram integration works
        // (valid bot token), (2) the bot is a member of that group, and (3) the
        // bot has administrator rights there. Nothing is persisted on failure.
        if (trim((string) $this->group_id) !== '') {
            $token = (string) ($settings->get('telegram.token') ?? '');

            if ($token === '') {
                $this->formErrors['group_id'] = 'Сначала настройте токен Telegram-бота в «Интеграции → Telegram».';

                return;
            }

            $verify = app(WebhookRegistrationService::class)->verifyTelegram($token, $this->group_id);

            if (! $verify['success']) {
                $this->formErrors['group_id'] = $verify['message'];

                return;
            }
        }

        // ── Persist ───────────────────────────────────────────────────────────
        $settings->set('telegram.template_topic_name', $this->template_topic_name ?? '');
        $settings->set('telegram.group_id', $this->group_id ?? '');

        $this->saved = true;
    }

    /**
     * Render the component view.
     *
     * @return \Illuminate\View\View
     */
    public function render(): \Illuminate\View\View
    {
        return view('livewire.settings.general-settings-page');
    }
}

<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Services\Settings\SettingsService;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Custom Livewire full-page component for the «Основные» settings screen.
 *
 * Reads and writes via SettingsService (DB → config() fallback, cache-backed).
 * Access: authenticated admin only (enforced in route middleware + mount()).
 * Layout: custom dark-sidebar admin layout (layouts.admin-settings).
 */
#[Layout('layouts.admin-settings')]
class GeneralSettingsPage extends Component
{
    /** @var string|null */
    public ?string $bot_name = null;

    /** @var string|null */
    public ?string $bot_description = null;

    /** @var string */
    public string $manager_interface = 'telegram_group';

    /** @var bool Show the restart-required notice */
    public bool $showRestartNotice = false;

    /** @var bool Show success banner */
    public bool $saved = false;

    /** @var array<string, string> */
    public array $formErrors = [];

    /**
     * Load current values from SettingsService on mount.
     */
    public function mount(SettingsService $settings): void
    {
        $this->bot_name = (string) ($settings->get('app.bot_name') ?? '');
        $this->bot_description = (string) ($settings->get('app.bot_description') ?? '');
        $this->manager_interface = (string) ($settings->get('app.manager_interface') ?? 'telegram_group');
    }

    /**
     * Save the form values via SettingsService.
     * Shows a restart notice when MANAGER_INTERFACE changes.
     */
    public function save(SettingsService $settings): void
    {
        $this->formErrors = [];
        $this->saved = false;

        // ── Validation ────────────────────────────────────────────────────────
        if (strlen((string) $this->bot_name) > 255) {
            $this->formErrors['bot_name'] = 'Максимальная длина — 255 символов.';
        }

        if (strlen((string) $this->bot_description) > 1000) {
            $this->formErrors['bot_description'] = 'Максимальная длина — 1000 символов.';
        }

        if (! in_array($this->manager_interface, ['telegram_group', 'admin_panel'], true)) {
            $this->formErrors['manager_interface'] = 'Выберите допустимое значение.';
        }

        if (! empty($this->formErrors)) {
            return;
        }

        // ── Detect interface change before saving ─────────────────────────────
        $previous = (string) ($settings->get('app.manager_interface') ?? 'telegram_group');
        $interfaceChanged = $previous !== $this->manager_interface;

        // ── Persist ───────────────────────────────────────────────────────────
        $settings->set('app.bot_name', $this->bot_name ?? '');
        $settings->set('app.bot_description', $this->bot_description ?? '');
        $settings->set('app.manager_interface', $this->manager_interface);

        $this->saved = true;
        $this->showRestartNotice = $interfaceChanged;
    }

    /**
     * Reset form fields to the values currently stored in SettingsService.
     */
    public function cancel(SettingsService $settings): void
    {
        $this->formErrors = [];
        $this->saved = false;
        $this->showRestartNotice = false;
        $this->bot_name = (string) ($settings->get('app.bot_name') ?? '');
        $this->bot_description = (string) ($settings->get('app.bot_description') ?? '');
        $this->manager_interface = (string) ($settings->get('app.manager_interface') ?? 'telegram_group');
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

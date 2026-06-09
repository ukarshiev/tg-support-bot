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
    /** @var string|null Telegram forum topic name template */
    public ?string $template_topic_name = null;

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
    }

    /**
     * Save the form values via SettingsService.
     */
    public function save(SettingsService $settings): void
    {
        $this->formErrors = [];
        $this->saved = false;

        // ── Validation ────────────────────────────────────────────────────────
        if (strlen((string) $this->template_topic_name) > 255) {
            $this->formErrors['template_topic_name'] = 'Максимальная длина — 255 символов.';
        }

        if (! empty($this->formErrors)) {
            return;
        }

        // ── Persist ───────────────────────────────────────────────────────────
        $settings->set('telegram.template_topic_name', $this->template_topic_name ?? '');

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

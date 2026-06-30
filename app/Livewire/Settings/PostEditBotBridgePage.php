<?php

namespace App\Livewire\Settings;

use App\Services\Settings\SettingsService;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.admin-settings')]
class PostEditBotBridgePage extends Component
{
    public bool $enabled = false;

    public string $api_url = '';

    public string $token = '';

    public int $timeout_ms = 5000;

    public int $cache_ttl_seconds = 60;

    public string $ai_mode = 'hybrid';

    public bool $show_client_card = true;

    public bool $saved = false;

    public function mount(SettingsService $settings): void
    {
        $this->enabled = (bool) ($settings->get('posteditbot_bridge.enabled') ?? false);
        $this->api_url = (string) ($settings->get('posteditbot_bridge.api_url') ?? '');
        $this->timeout_ms = (int) ($settings->get('posteditbot_bridge.timeout_ms') ?? 5000);
        $this->cache_ttl_seconds = (int) ($settings->get('posteditbot_bridge.cache_ttl_seconds') ?? 60);
        $this->ai_mode = (string) ($settings->get('posteditbot_bridge.ai_mode') ?? 'hybrid');
        $this->show_client_card = (bool) ($settings->get('posteditbot_bridge.show_client_card') ?? true);
    }

    public function save(SettingsService $settings): void
    {
        $this->validate([
            'api_url' => ['nullable', 'url'],
            'timeout_ms' => ['required', 'integer', 'min:1000', 'max:30000'],
            'cache_ttl_seconds' => ['required', 'integer', 'min:10', 'max:3600'],
            'ai_mode' => ['required', 'in:draft,hybrid,auto'],
        ]);

        $settings->set('posteditbot_bridge.enabled', $this->enabled);
        $settings->set('posteditbot_bridge.api_url', rtrim($this->api_url, '/'));
        if (trim($this->token) !== '') {
            $settings->set('posteditbot_bridge.token', trim($this->token));
            $this->token = '';
        }
        $settings->set('posteditbot_bridge.timeout_ms', $this->timeout_ms);
        $settings->set('posteditbot_bridge.cache_ttl_seconds', $this->cache_ttl_seconds);
        $settings->set('posteditbot_bridge.ai_mode', $this->ai_mode);
        $settings->set('posteditbot_bridge.show_client_card', $this->show_client_card);

        $this->saved = true;
    }

    public function render(): \Illuminate\View\View
    {
        return view('livewire.settings.posteditbot-bridge-page');
    }
}

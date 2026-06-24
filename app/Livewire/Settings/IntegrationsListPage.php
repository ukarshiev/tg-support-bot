<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Modules\Admin\Services\ChannelStatusService;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Integrations overview page — lists Telegram, VK, MAX channel cards
 * with connection status and links to per-channel config pages.
 *
 * Access: authenticated users via route middleware + custom Livewire route.
 * Layout: custom dark-sidebar admin layout (layouts.admin-settings).
 */
#[Layout('layouts.admin-settings')]
class IntegrationsListPage extends Component
{
    /**
     * @var array<string, array{connected: bool, label: string}>
     */
    public array $channelStatuses = [];

    /**
     * Load channel statuses on mount.
     */
    public function mount(ChannelStatusService $channelStatus): void
    {
        $this->channelStatuses = $channelStatus->all();
    }

    /**
     * Render the component view.
     *
     * @return \Illuminate\View\View
     */
    public function render(): \Illuminate\View\View
    {
        return view('livewire.settings.integrations-list-page');
    }
}

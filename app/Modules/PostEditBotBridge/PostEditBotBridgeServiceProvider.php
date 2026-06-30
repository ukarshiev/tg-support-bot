<?php

namespace App\Modules\PostEditBotBridge;

use App\Livewire\Settings\PostEditBotBridgePage;
use App\Modules\Admin\Middleware\EnsureSettingsAccess;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class PostEditBotBridgeServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'auth', EnsureSettingsAccess::class])
            ->prefix('admin/settings')
            ->name('admin.settings.')
            ->get('/posteditbot-bridge', PostEditBotBridgePage::class)
            ->name('posteditbot-bridge');
    }
}

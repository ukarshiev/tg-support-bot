<?php

namespace App\Modules\Admin;

use App\Livewire\Settings\GeneralSettingsPage;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AdminServiceProvider extends ServiceProvider
{
    /**
     * Зарегистрировать Admin-модуль.
     * Filament-роуты регистрируются через AdminPanelProvider.
     * Custom Livewire settings routes are registered here to avoid collision
     * with Filament's own route set (Filament owns /admin/* but does NOT
     * register /admin/settings/*).
     */
    public function boot(): void
    {
        // Custom Livewire Settings routes.
        // Prefix: /admin/settings — verified not claimed by Filament's panel.
        // Middleware: 'web' session stack + Filament's Authenticate guard so
        // unauthenticated visitors are redirected to /admin/login.
        Route::middleware(['web', Authenticate::class])
            ->prefix('admin/settings')
            ->name('admin.settings.')
            ->group(function (): void {
                Route::get('/general', GeneralSettingsPage::class)
                    ->name('general');

                // Future settings pages will be registered here.
            });
    }
}

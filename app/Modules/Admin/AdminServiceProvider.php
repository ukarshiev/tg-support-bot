<?php

namespace App\Modules\Admin;

use App\Livewire\Settings\AiAssistantPage;
use App\Livewire\Settings\AiProviderAccessPage;
use App\Livewire\Settings\GeneralSettingsPage;
use App\Livewire\Settings\IntegrationChannelPage;
use App\Livewire\Settings\IntegrationsListPage;
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

                // Integrations index — renders the mobile card-list page.
                // Desktop users are redirected client-side (window.innerWidth check)
                // by a script embedded in the list page view, so we avoid UA sniffing
                // and keep the Livewire route simple.
                // The list page is also directly accessible via /integrations/list.
                Route::get('/integrations', IntegrationsListPage::class)
                    ->name('integrations');

                Route::get('/integrations/list', IntegrationsListPage::class)
                    ->name('integrations.list');

                Route::get('/integrations/{channel}', IntegrationChannelPage::class)
                    ->name('integrations.channel')
                    ->where('channel', 'telegram|vk|max');

                // AI assistant settings.
                Route::get('/ai', AiAssistantPage::class)
                    ->name('ai');

                Route::get('/ai/{provider}', AiProviderAccessPage::class)
                    ->name('ai.provider')
                    ->where('provider', 'openai|deepseek|gigachat');
            });
    }
}

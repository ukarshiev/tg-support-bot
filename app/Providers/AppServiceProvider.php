<?php

namespace App\Providers;

use App\Contracts\ManagerInterfaceContract;
use App\Modules\Admin\Services\AdminPanelInterface;
use App\Modules\Telegram\Services\TelegramGroupInterface;
use App\Platform\PlatformChannelRegistry;
use App\Services\Settings\SettingsService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            ManagerInterfaceContract::class,
            config('app.manager_interface') === 'admin_panel'
                ? AdminPanelInterface::class
                : TelegramGroupInterface::class,
        );

        // Registry of pluggable platform channels. External platform modules
        // (e.g. the paid Avito package) register their PlatformChannel into this
        // singleton from their own ServiceProvider — without editing the core.
        $this->app->singleton(PlatformChannelRegistry::class);

        // Settings persistence layer — single shared instance throughout the
        // request lifecycle. Consumers inject SettingsService via the container
        // or resolve it with app(SettingsService::class).
        $this->app->singleton(SettingsService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}

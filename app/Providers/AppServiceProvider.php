<?php

namespace App\Providers;

use App\Contracts\ManagerInterfaceContract;
use App\Modules\Admin\Services\AdminPanelInterface;
use App\Modules\Telegram\Services\TelegramGroupInterface;
use App\Platform\PlatformChannelRegistry;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}

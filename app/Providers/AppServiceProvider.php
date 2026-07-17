<?php

namespace App\Providers;

use App\Models\Message;
use App\Observers\MessageObserver;
use App\Platform\PlatformChannelRegistry;
use App\Services\Settings\SettingsService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
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
        Message::observe(MessageObserver::class);

        RateLimiter::for('file-proxy', function (Request $request): Limit {
            return Limit::perMinute((int) config('file_proxy.requests_per_minute', 60))
                ->by((string) $request->ip());
        });
    }
}

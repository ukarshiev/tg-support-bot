<?php

namespace App\Providers;

use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Telescope::night();

        $this->hideSensitiveRequestDetails();

        $isLocal = $this->app->environment('local');

        Telescope::filter(function (IncomingEntry $entry) use ($isLocal) {
            return $isLocal ||
                   $entry->isReportableException() ||
                   $entry->isFailedRequest() ||
                   $entry->isFailedJob() ||
                   $entry->isScheduledTask() ||
                   $entry->hasMonitoredTag();
        });
    }

    /**
     * Prevent sensitive request details from being logged by Telescope.
     */
    protected function hideSensitiveRequestDetails(): void
    {
        if ($this->app->environment('local')) {
            return;
        }

        Telescope::hideRequestParameters(['_token']);

        Telescope::hideRequestHeaders([
            'cookie',
            'x-csrf-token',
            'x-xsrf-token',
        ]);
    }

    /**
     * Configure who can access Telescope.
     *
     * Overrides the package default (which leaves Telescope open in `local`).
     * Dashboard access is gated by App\Http\Middleware\TelescopeBasicAuth:
     *  - APP_DEBUG must be true (Telescope 404s on non-debug builds), and
     *  - HTTP Basic auth must match the env credentials
     *    (TELESCOPE_AUTH_USER / TELESCOPE_AUTH_PASSWORD).
     *
     * This callback (used by the package's own Authorize middleware) only
     * mirrors the debug gate; the credential check lives in the middleware.
     */
    protected function authorization(): void
    {
        Telescope::auth(fn ($request) => config('app.debug') === true);
    }
}

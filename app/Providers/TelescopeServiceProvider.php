<?php

namespace App\Providers;

use App\Models\User;
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
     * Dashboard access is gated by session-based admin auth — the primary
     * enforcement lives in the Telescope middleware stack
     * (`['web', 'auth', App\Http\Middleware\TelescopeAccess::class]`). This gate
     * mirrors that rule (admin role required) as defence in depth, overriding
     * the package default that leaves Telescope open in the `local` environment.
     */
    protected function authorization(): void
    {
        Telescope::auth(fn ($request) => ($user = $request->user()) instanceof User && $user->isAdmin());
    }
}

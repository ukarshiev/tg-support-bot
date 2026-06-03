<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
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
     * Overrides the package default, which leaves Telescope open in the `local`
     * environment. Access requires BOTH:
     *  - APP_DEBUG to be true (Telescope is unreachable on production builds
     *    where debug is off), and
     *  - the `viewTelescope` gate to pass (authenticated admin).
     * The gate is enforced in every environment — there is no `local` bypass.
     */
    protected function authorization(): void
    {
        $this->gate();

        Telescope::auth(fn ($request) => config('app.debug') === true
            && Gate::check('viewTelescope', [$request->user()]));
    }

    /**
     * Register the Telescope gate.
     *
     * Restricts the Telescope dashboard (/telescope) to admin operators
     * (User::isAdmin()), matching the admin-only access used across the
     * settings panel. A guest ($request->user() === null) is always denied.
     */
    protected function gate(): void
    {
        Gate::define('viewTelescope', fn (?User $user): bool => $user?->isAdmin() ?? false);
    }
}

<?php

return [
    /*
    |--------------------------------------------------------------------------
    | AI Service Configuration
    |--------------------------------------------------------------------------
    |
    | All runtime-configurable AI settings (provider, credentials, behaviour
    | flags) have been moved to the `settings` DB table and are accessed
    | exclusively via App\Services\Settings\SettingsService.
    |
    | Only infrastructure / deployment values that cannot be managed via the
    | admin UI remain here.
    |
    */

    /*
     * Path to the Blade system-prompt template.
     * This is a local filesystem path (infrastructure config) — stays in config.
     */
    'system_prompt_path' => resource_path('ai/system-prompt.blade.php'),
];

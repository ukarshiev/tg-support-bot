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
    | admin UI remain here. (The system prompt is stored in the DB under
    | `ai.system_prompt`, so there is nothing left to configure here.)
    |
    */
];

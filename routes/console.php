<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Keep the Telescope entries table bounded — drop records older than 48h.
// Requires a scheduler (cron running `php artisan schedule:run`); otherwise
// run `php artisan telescope:prune` manually.
Schedule::command('telescope:prune --hours=48')->daily();

<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily SaaS subscription expiry sweep (active past current_period_ends_at → past_due).
// Requires OS cron on the server: * * * * * php /path/to/artisan schedule:run
Schedule::command('saas:subscriptions-expire')->dailyAt('00:10');

// Nightly public-demo reset (15D-8): restore the five industry demos to clean sample data.
// Registered only; it does nothing until OS cron runs `php artisan schedule:run`.
if (config('saas.demos.enabled', true)) {
    Schedule::command('demo:reset-all --yes')
        ->dailyAt(config('saas.demos.reset_daily_at', '04:00'))
        ->withoutOverlapping();
}

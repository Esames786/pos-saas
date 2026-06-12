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

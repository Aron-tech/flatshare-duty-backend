<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('tasks:notify-unweighted')->hourly()->withoutOverlapping();
Schedule::command('rewards:release-stale-editing')->everyMinute()->withoutOverlapping();
Schedule::command('points:remind-week')->dailyAt('16:00')->timezone(config('app.week_timezone'))->withoutOverlapping();
Schedule::command('points:close-week')->dailyAt('00:05')->timezone(config('app.week_timezone'))->withoutOverlapping();
Schedule::command('activitylog:clean')->daily()->withoutOverlapping();
Schedule::command('tasks:expire-offers')->hourly()->withoutOverlapping();
Schedule::command('tasks:release-overdue')->hourly()->withoutOverlapping();
Schedule::command('tasks:generate-instances')->hourly()->withoutOverlapping();
Schedule::command('households:close-stale-departures')->daily()->withoutOverlapping();

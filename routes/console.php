<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('tasks:notify-unweighted')->hourly()->withoutOverlapping();
Schedule::command('rewards:release-stale-editing')->everyMinute()->withoutOverlapping();
Schedule::command('points:remind-week')->weeklyOn(0, '16:00')->timezone('Europe/Budapest')->withoutOverlapping();
Schedule::command('points:close-week')->weeklyOn(1, '00:05')->withoutOverlapping();
Schedule::command('activitylog:clean')->daily()->withoutOverlapping();
Schedule::command('tasks:generate-instances')->hourly()->withoutOverlapping();

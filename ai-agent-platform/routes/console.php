<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('campaigns:dispatch-due')->everyFiveMinutes()->withoutOverlapping()->onOneServer();

Schedule::call(fn () => cache()->put('ops.scheduler_heartbeat', now()->toIso8601String(), 3600))
    ->name('ops.scheduler_heartbeat')
    ->everyMinute();

<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('reports:purge-snapshots')
    ->dailyAt('02:15')
    ->withoutOverlapping();

Schedule::command('reports:dispatch-schedules')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('freshservice:refresh-directory')
    ->dailyAt('01:00')
    ->withoutOverlapping()
    ->onOneServer();

// Security agent: continuous detection against owned telemetry.
Schedule::command('security:scan --quiet-ok')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('security:purge-history')
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->onOneServer();

// SEO trend history: capture Search Console snapshots nightly, prune old ones.
Schedule::command('seo:capture-snapshots')
    ->dailyAt('04:00')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('seo:purge-snapshots')
    ->dailyAt('04:30')
    ->withoutOverlapping()
    ->onOneServer();

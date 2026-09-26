<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('wallet:verify')
    ->dailyAt('03:30')
    ->timezone('UTC')
    ->appendOutputTo(storage_path('logs/wallet-verify.log'));

Schedule::command('sport:sync-leagues')->dailyAt('04:00')->timezone('UTC');
Schedule::command('sport:sync-fixtures')->everyThreeHours();
Schedule::command('sport:sync-odds')->everyThreeHours();
Schedule::command('sport:sync-odds --soon')->everyThirtyMinutes();
Schedule::command('sport:sync-results')->everySixHours();
Schedule::command('sport:live-sync')->everyTwoMinutes()->withoutOverlapping();
Schedule::command('sport:settle-check')->everyTenMinutes()->withoutOverlapping();
Schedule::command('sport:stats-refresh')->everyTenMinutes()->withoutOverlapping();
Schedule::command('sport:stats-close')->dailyAt('00:20')->timezone('UTC')->withoutOverlapping();
Schedule::command('sport:translate')->hourly();

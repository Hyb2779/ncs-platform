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

Schedule::command('sport:stats-refresh')->everyTenMinutes()->withoutOverlapping();
Schedule::command('sport:stats-close')->dailyAt('00:20')->timezone('UTC')->withoutOverlapping();
Schedule::command('sport:translate')->hourly();
Schedule::command('sport:fenix-prematch')->everyFiveMinutes()->withoutOverlapping(15);
Schedule::command('sport:fenix-results')->everyTenMinutes()->withoutOverlapping(15);

// RomaSpin oyun listesi: yeni oyunlar + GoldPalace önceliği (04:30 TR).
Schedule::command('casino:sync romaspin')->dailyAt('01:30')->withoutOverlapping(60);

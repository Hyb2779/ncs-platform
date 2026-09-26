<?php

namespace App\Console\Commands;

use Database\Seeders\DemoHistorySeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;

class DemoReset extends Command
{
    protected $signature = 'demo:reset';

    protected $description = 'Rebuild the demo tree, thirty days of play, and daily stats';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error(__('wallet.errors.demo_reset_forbidden'));

            return self::FAILURE;
        }

        $this->call('migrate:fresh', ['--force' => true]);
        $this->call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);
        $this->call('db:seed', ['--class' => DemoHistorySeeder::class, '--force' => true]);
        $this->call('sport:stats-backfill');

        return $this->call('wallet:verify');
    }
}

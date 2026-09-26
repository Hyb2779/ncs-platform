<?php

namespace App\Console\Commands;

use App\Services\Casino\ProviderRegistry;
use Database\Seeders\DemoHistorySeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;
use Throwable;

class DemoReset extends Command
{
    protected $signature = 'demo:reset';

    protected $description = 'Rebuild the demo tree, provider data, thirty days of play, and daily stats';

    public function handle(ProviderRegistry $registry): int
    {
        if (app()->isProduction()) {
            $this->error(__('wallet.errors.demo_reset_forbidden'));

            return self::FAILURE;
        }

        $this->call('migrate:fresh', ['--force' => true]);
        $this->call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);

        // migrate:fresh wipes provider data too: restore it before the history seeder uses it.
        $this->call('sport:sync-leagues');
        $this->call('sport:sync-fixtures');
        $this->call('sport:sync-odds');

        try {
            $count = $registry->get('goldpalace')?->syncGames() ?? 0;
            $this->info("goldpalace games={$count}");
        } catch (Throwable $e) {
            $this->warn('goldpalace game sync failed: '.$e->getMessage());
        }

        $this->call('db:seed', ['--class' => DemoHistorySeeder::class, '--force' => true]);
        $this->call('sport:stats-backfill');

        return $this->call('wallet:verify');
    }
}

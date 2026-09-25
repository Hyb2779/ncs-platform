<?php

namespace App\Console\Commands;

use App\Services\Sport\SportSync;
use Illuminate\Console\Command;

class SportSyncResults extends Command
{
    protected $signature = 'sport:sync-results';

    protected $description = 'Refresh scores for matches that have started';

    public function handle(SportSync $sync): int
    {
        $this->info('results='.$sync->results());
        $this->info('suspended='.$sync->suspendStarted());

        return self::SUCCESS;
    }
}

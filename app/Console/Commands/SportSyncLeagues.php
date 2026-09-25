<?php

namespace App\Console\Commands;

use App\Services\Sport\SportSync;
use Illuminate\Console\Command;

class SportSyncLeagues extends Command
{
    protected $signature = 'sport:sync-leagues';

    protected $description = 'Sync the selected football leagues';

    public function handle(SportSync $sync): int
    {
        $this->info('leagues='.$sync->leagues());

        return self::SUCCESS;
    }
}

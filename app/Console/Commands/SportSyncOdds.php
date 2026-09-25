<?php

namespace App\Console\Commands;

use App\Services\Sport\SportSync;
use Illuminate\Console\Command;

class SportSyncOdds extends Command
{
    protected $signature = 'sport:sync-odds {--soon : Only matches starting within two hours}';

    protected $description = 'Sync prematch odds from the configured bookmaker';

    public function handle(SportSync $sync): int
    {
        $this->info('odds='.$sync->odds((bool) $this->option('soon')));

        return self::SUCCESS;
    }
}

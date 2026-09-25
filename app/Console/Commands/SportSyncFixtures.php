<?php

namespace App\Console\Commands;

use App\Services\Sport\SportSync;
use Illuminate\Console\Command;

class SportSyncFixtures extends Command
{
    protected $signature = 'sport:sync-fixtures';

    protected $description = 'Sync fixtures for the next three days';

    public function handle(SportSync $sync): int
    {
        $this->info('fixtures='.$sync->fixtures());

        return self::SUCCESS;
    }
}

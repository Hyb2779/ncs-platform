<?php

namespace App\Console\Commands;

use App\Services\Sport\LiveSync;
use Illuminate\Console\Command;

class SportLiveSync extends Command
{
    protected $signature = 'sport:live-sync';

    protected $description = 'Refresh live scores for fixtures that have a pending selection';

    public function handle(LiveSync $sync): int
    {
        $this->info('updated='.$sync->run());

        return self::SUCCESS;
    }
}

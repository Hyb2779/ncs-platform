<?php

namespace App\Console\Commands;

use App\Services\Sport\FenixSync;
use Illuminate\Console\Command;

class FenixPrematch extends Command
{
    protected $signature = 'sport:fenix-prematch';

    protected $description = 'Fenix maç önü beslemesinden bülteni günceller (futbol).';

    public function handle(FenixSync $sync): int
    {
        ini_set('memory_limit', '1024M');
        $started = microtime(true);
        $stats = $sync->prematch();
        $this->info(sprintf('etkinlik %d | maç %d | oran %d | hata %d | %.1f sn | bellek %d MB',
            $stats['events'], $stats['fixtures'], $stats['odds'], $stats['errors'], microtime(true) - $started, memory_get_peak_usage(true) / 1048576));

        return self::SUCCESS;
    }
}

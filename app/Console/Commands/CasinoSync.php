<?php

namespace App\Console\Commands;

use App\Services\Casino\ProviderRegistry;
use Illuminate\Console\Command;

/** Sağlayıcının oyun listesini senkronlar. RomaSpin her gece zamanlayıcıdan çalışır (GoldPalace önceliği yeniden hesaplanır). */
class CasinoSync extends Command
{
    protected $signature = 'casino:sync {provider : goldpalace | romaspin}';

    protected $description = 'Casino sağlayıcısının oyun listesini senkronlar';

    public function handle(ProviderRegistry $registry): int
    {
        $driver = $registry->get((string) $this->argument('provider'));
        if ($driver === null) {
            $this->error('Bilinmeyen sağlayıcı: '.$this->argument('provider'));

            return self::FAILURE;
        }

        $count = $driver->syncGames();
        $this->info('Senkronlanan oyun: '.$count);

        return $count > 0 ? self::SUCCESS : self::FAILURE;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\CasinoGame;
use App\Services\GameImages;
use Illuminate\Console\Command;

class WarmGameImagesCommand extends Command
{
    protected $signature = 'casino:warm-images {--limit=0 : 0 = hepsi}';

    protected $description = 'Aktif oyunların küçültülmüş WebP görsellerini önceden üretir';

    public function handle(GameImages $images): int
    {
        $query = CasinoGame::query()->where('is_active', true)->whereNotNull('image_url')->orderByDesc('is_popular')->orderBy('id');
        if ((int) $this->option('limit') > 0) {
            $query->limit((int) $this->option('limit'));
        }

        $made = 0;
        $skip = 0;
        $fail = 0;
        foreach ($query->cursor() as $game) {
            if ($images->exists($game)) {
                $skip++;

                continue;
            }
            $images->generate($game) ? $made++ : $fail++;
        }

        $this->info("uretilen: {$made}, zaten vardi: {$skip}, basarisiz: {$fail}");

        return self::SUCCESS;
    }
}

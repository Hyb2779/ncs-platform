<?php

namespace App\Console\Commands;

use App\Models\SportFixture;
use App\Services\Sport\FenixResults;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FenixResultsCommand extends Command
{
    protected $signature = 'sport:fenix-results {--limit=40}';

    protected $description = 'Biten Fenix maçlarının skorunu resultbot\'tan yazar (Sonuçlar sayfası)';

    public function handle(FenixResults $results): int
    {
        $candidates = SportFixture::query()
            ->where('status', 'NS')
            ->where(fn ($q) => $q->whereNull('score_source')->orWhere('score_source', '!=', 'manual'))
            ->whereBetween('starts_at', [now()->subDays(3), now()->subMinutes(120)])
            ->orderByDesc('starts_at')
            ->limit((int) $this->option('limit') * 3)
            ->get();

        $checked = 0;
        $done = 0;
        foreach ($candidates as $fixture) {
            if ($checked >= (int) $this->option('limit')) {
                break;
            }
            // Aynı maç en fazla 30 dakikada bir denenir (ertelenen/iptal maç kuyruğu tıkamasın).
            if (! Cache::add('fenix_result_try:'.$fixture->id, 1, now()->addMinutes(30))) {
                continue;
            }
            $checked++;

            try {
                $r = $results->fetch((string) $fixture->api_id);
            } catch (\Throwable $e) {
                Log::warning('sport.fenix_results.fetch_failed', ['fixture' => $fixture->id, 'event' => $fixture->api_id, 'error' => $e->getMessage()]);

                continue;
            }
            if ($r === null) {
                continue;
            }

            $fixture->forceFill([
                'status' => 'FT',
                'score_home' => $r['ft'][0],
                'score_away' => $r['ft'][1],
                'ft_home' => $r['ft'][0],
                'ft_away' => $r['ft'][1],
                'ht_home' => $r['ht'][0] ?? null,
                'ht_away' => $r['ht'][1] ?? null,
                'score_source' => 'fenix',
            ])->save();
            $done++;
        }

        $this->info("kontrol: {$checked}, sonuclanan: {$done}");

        if ($done > 0) {
            app(\App\Services\Sport\ResultBoard::class)->forget();
        }

        return self::SUCCESS;
    }
}

<?php

namespace App\Services\Sport;

use App\Models\SportFixture;
use Illuminate\Database\Eloquent\Builder;

/** Bülten maç seçimi: spor sayfası ve ana sayfa aynı kuralı kullanır. */
class Bulletin
{
    public static function query(): Builder
    {
        [$from, $until] = display_span_utc(0, 3);

        return SportFixture::query()
            ->with(['league.country', 'home', 'away', 'odds.market'])
            ->whereHas('league', fn ($q) => $q->where('is_active', true))
            ->whereHas('odds')
            ->where('starts_at', '>=', $from)
            ->where('starts_at', '<', $until)
            ->orderBy('starts_at');
    }
}

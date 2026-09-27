<?php

namespace App\Services\Sport;

use App\Models\SportFixture;
use Illuminate\Database\Eloquent\Builder;

/** Bülten maç seçimi: spor sayfası ve ana sayfa aynı kuralı kullanır. */
class Bulletin
{
    public static function query(): Builder
    {
        return SportFixture::query()
            ->with(['league.country', 'home', 'away', 'odds.market'])
            ->whereHas('league', fn ($q) => $q->where('is_active', true))
            ->whereHas('odds')
            ->where('starts_at', '>=', now()->utc()->startOfDay())
            ->where('starts_at', '<', now()->utc()->addDays(3)->endOfDay())
            ->orderBy('starts_at');
    }
}

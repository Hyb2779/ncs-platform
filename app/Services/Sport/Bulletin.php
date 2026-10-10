<?php

namespace App\Services\Sport;

use App\Models\SportFixture;
use App\Models\SportMarket;
use Illuminate\Database\Eloquent\Builder;

/** Bülten maç seçimi: spor sayfası ve ana sayfa aynı kuralı kullanır. */
class Bulletin
{
    public static function query(): Builder
    {
        return SportFixture::query()
            ->with([
                'league.country',
                'home',
                'away',
                'odds' => fn ($query) => $query->where('suspended', false)->whereIn(
                    'market_id',
                    SportMarket::query()->whereIn('code', ['1X2', 'DC', 'OU15', 'OU25', 'OU35', 'BTTS', 'HT1X2'])->select('id'),
                ),
                'odds.market',
            ])
            ->whereHas('league', fn ($q) => $q->where('is_active', true))
            ->where('offer_count', '>', 0)
            ->where('starts_at', '>=', now())
            ->orderBy('starts_at');
    }

    public static function liveQuery(): Builder
    {
        return SportFixture::query()
            ->with([
                'league.country',
                'home',
                'away',
                'odds' => fn ($query) => $query->where('suspended', false)->whereIn(
                    'market_id',
                    SportMarket::query()->whereIn('code', ['1X2', 'DC', 'OU15', 'OU25', 'OU35', 'BTTS', 'HT1X2'])->select('id'),
                ),
                'odds.market',
            ])
            ->whereHas('league', fn ($q) => $q->where('is_active', true))
            ->whereIn('status', [...config('sport.live_statuses'), 'HT'])
            ->orderByRaw("case status when '1H' then 1 when '2H' then 2 when 'LIVE' then 3 when 'ET' then 4 when 'P' then 5 when 'BT' then 6 when 'HT' then 7 else 8 end")
            ->orderByDesc('elapsed');
    }
}

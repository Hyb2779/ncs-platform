<?php

namespace App\Services\Sport;

use App\Models\SportFixture;
use Illuminate\Support\Collection;

/** Üst şerit: oynanan maçlar, devre arasında bekleyenler sonda. */
class LiveTicker
{
    public static function fixtures(?string $sport = null, int $limit = 40): Collection
    {
        $query = SportFixture::query()
            ->with(['home', 'away'])
            ->whereIn('status', [...config('sport.live_statuses'), 'HT'])
            ->orderByRaw("case status when '1H' then 1 when '2H' then 2 when 'LIVE' then 3 when 'ET' then 4 when 'P' then 5 when 'BT' then 6 when 'HT' then 7 else 8 end")
            ->orderByDesc('elapsed')
            ->limit($limit);

        if ($sport !== null) {
            $query->where('sport', $sport);
        }

        return $query->get();
    }
}

<?php

namespace App\Services\Casino;

use App\Services\HomeSlides;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Aktif oyun kataloğunun ham listesi. Bayi engeli bu listede yoktur; istek sırasında uygulanır. */
class CatalogCache
{
    public const KEY = 'casino:catalog:rows';

    public const TTL = 600;

    /**
     * @return list<array{id: int, provider: string, vendor: ?string, category: ?string, is_live: bool, is_popular: bool}>
     */
    public function rows(): array
    {
        $rows = Cache::remember(self::KEY, self::TTL, function () {
            return DB::table('casino_games as g')
                ->join('casino_providers as p', 'p.id', '=', 'g.provider_id')
                ->where('g.is_active', true)
                ->where('p.status', 'active')
                ->orderBy('g.id')
                ->get(['g.id', 'p.code as provider', 'g.vendor', 'g.category', 'g.is_live', 'g.is_popular'])
                ->map(fn ($row) => [
                    'id' => (int) $row->id,
                    'provider' => (string) $row->provider,
                    'vendor' => $row->vendor !== null && $row->vendor !== '' ? (string) $row->vendor : null,
                    'category' => $row->category !== null && $row->category !== '' ? (string) $row->category : null,
                    'is_live' => (bool) $row->is_live,
                    'is_popular' => (bool) $row->is_popular,
                ])->all();
        });

        return is_array($rows) ? $rows : [];
    }

    public function forget(): void
    {
        Cache::forget(self::KEY);
        Cache::forget(HomeCasinoRails::SLOTS_KEY);
        Cache::forget(HomeCasinoRails::LIVE_KEY);
        Cache::forget('casino:slot-plays:7d');
        foreach (['slot', 'live', 'mini', 'virtual'] as $mode) {
            Cache::forget('casino:lobby-plays:'.$mode);
        }
        app(HomeSlides::class)->forget();
    }
}

<?php

namespace App\Services\Casino;

use App\Models\CasinoGame;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Ana sayfa slot ve canlı casino şeritleri. Sıralama 15 dk önbellekte. */
class HomeCasinoRails
{
    private const CACHE_SECONDS = 900;

    public function popularSlots(?User $user, int $limit = 12): Collection
    {
        $version = GameAvailability::cacheVersion();
        $scope = $this->scopeKey($user);

        $ids = Cache::remember("casino:home-slots:{$version}:{$scope}:{$limit}", self::CACHE_SECONDS, function () use ($user, $limit) {
            return $this->rankIds($this->slots($user), $limit);
        });

        return $this->hydrate($this->slots($user), $ids);
    }

    public function liveTables(?User $user, int $limit = 12): Collection
    {
        $version = GameAvailability::cacheVersion();
        $scope = $this->scopeKey($user);

        $ids = Cache::remember("casino:home-live:{$version}:{$scope}:{$limit}", self::CACHE_SECONDS, function () use ($user, $limit) {
            return $this->liveQuery($user)->orderBy('sort_order')->orderBy('id')->limit($limit)->pluck('id')->map(fn ($id) => (int) $id)->all();
        });

        return $this->hydrate($this->liveQuery($user), $ids);
    }

    public function ranked(Builder $query, int $limit): Collection
    {
        return $this->hydrate($query, $this->rankIds($query, $limit));
    }

    private function slots(?User $user): Builder
    {
        $query = CasinoGame::query()
            ->with('provider')
            ->where('is_active', true)
            ->where('is_live', false)
            ->where(fn ($q) => $q->whereNull('category')->orWhereNotIn('category', ['virtual', 'mini']))
            ->whereHas('provider', fn ($q) => $q->where('status', 'active'));

        return app(GameAvailability::class)->apply($query, $user);
    }

    private function liveQuery(?User $user): Builder
    {
        $query = CasinoGame::query()
            ->with('provider')
            ->where('is_active', true)
            ->where('is_live', true)
            ->whereHas('provider', fn ($q) => $q->where('status', 'active')->where('code', 'romaspin'));

        return app(GameAvailability::class)->apply($query, $user);
    }

    /**
     * @return list<int>
     */
    private function rankIds(Builder $query, int $limit): array
    {
        $plays = $this->playCounts();
        $rows = (clone $query)->setEagerLoads([])->reorder()
            ->select('casino_games.id', 'casino_games.is_popular', 'casino_games.sort_order')
            ->get();

        return $rows->sort(function ($a, $b) use ($plays) {
            return ($plays[$b->id] ?? 0) <=> ($plays[$a->id] ?? 0)
                ?: ((int) $b->is_popular) <=> ((int) $a->is_popular)
                ?: ((int) $a->sort_order) <=> ((int) $b->sort_order)
                ?: $a->id <=> $b->id;
        })->take($limit)->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /**
     * Son 7 gündeki slot bahisleri (aynı round bir kez). Yetersizse sıralama is_popular, sonra sağlayıcı sırasına düşer.
     *
     * @return array<int, int>
     */
    private function playCounts(): array
    {
        return Cache::remember('casino:slot-plays:7d', self::CACHE_SECONDS, function () {
            return DB::table('game_rounds as r')
                ->join('casino_games as g', 'g.id', '=', 'r.game_id')
                ->where('r.created_at', '>=', now()->subDays(7))
                ->where('r.status', 'bet')
                ->where('g.is_live', false)
                ->where(fn ($q) => $q->whereNull('g.category')->orWhereNotIn('g.category', ['mini', 'virtual']))
                ->groupBy('r.game_id')
                ->selectRaw("r.game_id as game_id, COUNT(DISTINCT COALESCE(NULLIF(r.round_id, ''), r.provider_transaction_id)) as plays")
                ->pluck('plays', 'game_id')
                ->map(fn ($count) => (int) $count)
                ->all();
        });
    }

    /**
     * @param  list<int>  $ids
     */
    private function hydrate(Builder $query, array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        $games = (clone $query)->whereIn('casino_games.id', $ids)->get()->keyBy('id');

        return collect($ids)->map(fn ($id) => $games->get($id))->filter()->values();
    }

    private function scopeKey(?User $user): string
    {
        $ids = GameAvailability::scopeIdsFor($user);

        return $ids === [] ? 'g' : implode('-', $ids);
    }
}

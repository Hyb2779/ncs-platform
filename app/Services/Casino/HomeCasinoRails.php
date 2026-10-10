<?php

namespace App\Services\Casino;

use App\Models\CasinoGame;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Ana sayfa slot ve canlı casino şeritleri. Sıralama 10 dk ham önbellekte; engel her istekte uygulanır. */
class HomeCasinoRails
{
    public const SLOTS_KEY = 'casino:home-slots';

    public const LIVE_KEY = 'casino:home-live';

    private const CACHE_SECONDS = 600;

    public function popularSlots(?User $user, int $limit = 12): Collection
    {
        $ids = Cache::remember(self::SLOTS_KEY, self::CACHE_SECONDS, function () {
            return $this->rankIds($this->slotQuery());
        });

        return $this->hydrate($this->slots($user), $this->allowed(is_array($ids) ? $ids : [], $user, $limit));
    }

    public function liveTables(?User $user, int $limit = 12): Collection
    {
        $ids = Cache::remember(self::LIVE_KEY, self::CACHE_SECONDS, function () {
            return $this->liveBase()->orderBy('sort_order')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        });

        return $this->hydrate($this->liveQuery($user), $this->allowed(is_array($ids) ? $ids : [], $user, $limit));
    }

    public function ranked(Builder $query, int $limit): Collection
    {
        return $this->hydrate($query, $this->rankIds($query, $limit));
    }

    private function slotQuery(): Builder
    {
        return CasinoGame::query()
            ->with('provider')
            ->where('is_active', true)
            ->where('is_live', false)
            ->where(fn ($q) => $q->whereNull('category')->orWhereNotIn('category', ['virtual', 'mini']))
            ->whereHas('provider', fn ($q) => $q->where('status', 'active'));
    }

    private function slots(?User $user): Builder
    {
        return app(GameAvailability::class)->apply($this->slotQuery(), $user);
    }

    private function liveBase(): Builder
    {
        return CasinoGame::query()
            ->with('provider')
            ->where('is_active', true)
            ->where('is_live', true)
            ->whereHas('provider', fn ($q) => $q->where('status', 'active')->where('code', 'romaspin'));
    }

    private function liveQuery(?User $user): Builder
    {
        return app(GameAvailability::class)->apply($this->liveBase(), $user);
    }

    /**
     * @return list<int>
     */
    private function rankIds(Builder $query, ?int $limit = null): array
    {
        $plays = $this->playCounts();
        $rows = (clone $query)->setEagerLoads([])->reorder()
            ->select('casino_games.id', 'casino_games.is_popular', 'casino_games.sort_order')
            ->toBase()
            ->get();

        $sorted = $rows->sort(function ($a, $b) use ($plays): int {
            $aId = (int) $a->id;
            $bId = (int) $b->id;

            return ($plays[$bId] ?? 0) <=> ($plays[$aId] ?? 0)
                ?: ((int) $b->is_popular) <=> ((int) $a->is_popular)
                ?: ((int) $a->sort_order) <=> ((int) $b->sort_order)
                ?: $aId <=> $bId;
        });

        if ($limit !== null) {
            $sorted = $sorted->take($limit);
        }

        return $sorted->map(fn ($row) => (int) $row->id)->values()->all();
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

    /**
     * Ham sıradan bu izleyiciye açık ilk oyunlar. Engel önbellekteki listeye yazılmaz.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function allowed(array $ids, ?User $user, int $limit): array
    {
        $meta = [];
        foreach (app(CatalogCache::class)->rows() as $row) {
            $meta[$row['id']] = $row;
        }
        $availability = app(GameAvailability::class);
        $picked = [];
        foreach ($ids as $id) {
            $row = $meta[(int) $id] ?? null;
            if ($row === null || ! $availability->visible($row, $user)) {
                continue;
            }
            $picked[] = (int) $id;
            if (count($picked) >= $limit) {
                break;
            }
        }

        return $picked;
    }
}

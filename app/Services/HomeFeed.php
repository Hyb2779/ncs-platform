<?php

namespace App\Services;

use App\Enums\Currency;
use App\Models\CasinoGame;
use App\Models\User;
use App\Services\Casino\CatalogCache;
use App\Services\Casino\GameAvailability;
use App\Support\Money;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Ana sayfa (vitrin) verisi. Katalog ham önbellekten gelir; kategori sayacı engelden sonra sayılır. */
class HomeFeed
{
    /** Son kazanç şeridi: bu tutarın altı gösterilmez. */
    private const WIN_FLOOR = [
        'TRY' => '50.00',
        'USD' => '2.00',
        'EUR' => '2.00',
        'AED' => '10.00',
    ];

    public function __construct(
        private readonly GameImages $images,
        private readonly GameAvailability $availability,
    ) {}

    public function build(?User $viewer): array
    {
        return [
            'quick' => $this->counts($viewer),
            'winners' => $this->winners($viewer),
        ];
    }

    /**
     * @return array{slots: int, mini: int, casino: int}
     */
    private function counts(?User $viewer): array
    {
        $availability = app(GameAvailability::class);
        $slots = 0;
        $mini = 0;
        $live = 0;
        foreach (app(CatalogCache::class)->rows() as $row) {
            if (! $availability->visible($row, $viewer)) {
                continue;
            }
            if ($row['is_live']) {
                $live++;
            } elseif (($row['category'] ?? null) === 'mini') {
                $mini++;
            } elseif (($row['category'] ?? null) !== 'virtual') {
                $slots++;
            }
        }

        return ['slots' => $slots, 'mini' => $mini, 'casino' => $live];
    }

    private function winners(?User $viewer): array
    {
        if ($viewer !== null && $viewer->superadmin_id === null) {
            return [];
        }

        $scope = $viewer?->superadmin_id ?? 'public';
        $locale = app()->getLocale();
        $cached = Cache::remember('home:winners:'.$scope.':'.$locale, 120, fn () => $this->winnerRows($viewer));

        return is_array($cached) ? $cached : [];
    }

    /**
     * Son 24 saatin slot ve canlı casino kazançları, yeniden eskiye. Sporun oyun görseli yok.
     *
     * @return list<array{user: string, game: string, game_id: int, image: string, amount: string}>
     */
    private function winnerRows(?User $viewer): array
    {
        $query = DB::table('wallet_transactions as t')
            ->join('wallets as w', 'w.id', '=', 't.wallet_id')
            ->join('users as u', 'u.id', '=', 'w.user_id')
            ->where('t.type', 'win')
            ->whereIn('t.product', ['slot', 'live_casino'])
            ->where('u.role', 'uye')
            ->where('t.created_at', '>=', now()->subDay())
            ->where(function ($filter): void {
                foreach (self::WIN_FLOOR as $currency => $floor) {
                    $filter->orWhere(fn ($row) => $row->where('w.currency', $currency)->where('t.amount', '>=', $floor));
                }
            })
            ->orderByDesc('t.created_at')
            ->orderByDesc('t.id')
            ->limit(40);

        if ($viewer?->superadmin_id !== null) {
            $query->where('u.superadmin_id', $viewer->superadmin_id);
        }

        $found = $query->get(['t.idempotency_key', 't.amount', 't.note', 'u.username', 'w.currency']);
        $games = $this->gamesFor($found->pluck('idempotency_key')->all());
        $rows = [];

        foreach ($found as $row) {
            $game = $games[(string) $row->idempotency_key] ?? null;
            if ($game === null || ! $this->availability->visible([
                'id' => (int) $game->id,
                'provider' => (string) ($game->provider_code ?? ''),
                'vendor' => $game->vendor,
                'category' => $game->category,
                'is_live' => (bool) $game->is_live,
            ], $viewer)) {
                continue;
            }

            $name = trim((string) ($game->name !== '' ? $game->name : $row->note));
            if ($name === '') {
                continue;
            }

            $rows[] = [
                'user' => $this->mask((string) $row->username),
                'game' => $name,
                'game_id' => (int) $game->id,
                'image' => $this->image($game),
                'amount' => Money::formatSigned((string) $row->amount, Currency::from($row->currency)),
            ];

            if (count($rows) === 12) {
                break;
            }
        }

        return $rows;
    }

    /**
     * @param  list<mixed>  $keys
     * @return array<string, object>
     */
    private function gamesFor(array $keys): array
    {
        $ids = [];
        foreach ($keys as $key) {
            $key = (string) $key;
            $pos = strpos($key, ':');
            if ($pos !== false) {
                $ids[] = substr($key, $pos + 1);
            }
        }
        if ($ids === []) {
            return [];
        }

        $map = [];
        $games = DB::table('game_rounds as r')
            ->join('casino_games as g', 'g.id', '=', 'r.game_id')
            ->leftJoin('casino_providers as p', 'p.id', '=', 'g.provider_id')
            ->whereIn('r.provider_transaction_id', array_values(array_unique($ids)))
            ->get(['r.provider', 'r.provider_transaction_id', 'g.id', 'g.name', 'g.image_url', 'g.is_live', 'g.category', 'g.vendor', 'p.code as provider_code']);

        foreach ($games as $game) {
            $map[$game->provider.':'.$game->provider_transaction_id] = $game;
        }

        return $map;
    }

    private function image(object $game): string
    {
        if ($game->image_url === null || $game->image_url === '') {
            return '';
        }

        $model = new CasinoGame;
        $model->id = (int) $game->id;
        $model->image_url = (string) $game->image_url;

        return (string) ($this->images->url($model) ?? '');
    }

    private function mask(string $username): string
    {
        return mb_substr($username, 0, 2).'***'.mb_substr($username, -2);
    }
}

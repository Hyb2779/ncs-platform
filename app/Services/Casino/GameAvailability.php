<?php

namespace App\Services\Casino;

use App\Models\CasinoGame;
use App\Models\GameBlock;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Oyun ac/kapat icin tek karar noktasi. casino_games.is_active senkronun alanidir;
 * admin engelleri game_blocks'ta tutulur, senkron onlara dokunmaz.
 */
class GameAvailability
{
    public const SCOPES = ['provider', 'vendor', 'category', 'game', 'product', 'sport'];

    public const SPORTS = ['football', 'basketball', 'tennis', 'volleyball'];

    public const CATEGORIES = ['slot', 'live', 'mini'];

    /** Casino disi urunler (casino filtrelerini etkilemez). */
    public const PRODUCTS = ['wegas_sport'];

    private const VERSION_KEY = 'casino:game_blocks:version';

    /** Ziyaretci/owner: null (sadece genel), superadmin: kendisi, bayi/uye: bagli oldugu superadmin. */
    public static function superadminIdFor(?User $user): ?int
    {
        if ($user === null) {
            return null;
        }
        $role = $user->role instanceof \BackedEnum ? $user->role->value : (string) $user->role;

        return match ($role) {
            'owner' => null,
            'superadmin' => (int) $user->id,
            default => $user->superadmin_id !== null ? (int) $user->superadmin_id : null,
        };
    }

    /**
     * Kullanicinin bagli oldugu engel katmanlari: yolundaki tum ustler (alt owner, superadmin, bayi) + superadmin_id.
     * Kok owner engelleri NULL saklanir (genel); alt owner (or. Volkan) kendi id'siyle, sadece kendi agacina uygulanir.
     *
     * @return list<int>
     */
    public static function scopeIdsFor(?User $user): array
    {
        if ($user === null || $user->isRootOwner()) {
            return [];
        }
        $ids = array_map('intval', array_filter(explode('/', (string) $user->path), fn ($v) => $v !== ''));
        if ($user->superadmin_id !== null) {
            $ids[] = (int) $user->superadmin_id;
        }
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /**
     * @param  int|list<int>|null  $scope  eski cagrilar icin tek superadmin id'si de kabul edilir
     * @return array<string, list<string>> genel + verilen katmanlarin engelleri
     */
    public function blocked(int|array|null $scope): array
    {
        $version = (int) Cache::get(self::VERSION_KEY, 1);

        $ids = is_array($scope) ? $scope : ($scope === null ? [] : [$scope]);
        $key = $ids === [] ? 'g' : implode('-', $ids);

        return Cache::remember("casino:game_blocks:{$version}:".$key, 600, function () use ($ids) {
            $rows = GameBlock::query()
                ->where(function ($q) use ($ids) {
                    $q->whereNull('superadmin_id');
                    if ($ids !== []) {
                        $q->orWhereIn('superadmin_id', $ids);
                    }
                })
                ->get(['scope', 'value']);

            $out = array_fill_keys(self::SCOPES, []);
            foreach ($rows as $row) {
                if (isset($out[$row->scope])) {
                    $out[$row->scope][] = (string) $row->value;
                }
            }

            return array_map(fn ($v) => array_values(array_unique($v)), $out);
        });
    }

    public function apply(Builder $query, ?User $user): Builder
    {
        $b = $this->blocked(self::scopeIdsFor($user));
        $t = $query->getModel()->getTable();

        if ($b['provider'] !== []) {
            $query->whereHas('provider', fn ($q) => $q->whereNotIn('code', $b['provider']));
        }
        if ($b['vendor'] !== []) {
            $query->where(fn ($q) => $q->whereNull("$t.vendor")->orWhereNotIn("$t.vendor", $b['vendor']));
        }
        if ($b['game'] !== []) {
            $query->whereNotIn("$t.id", array_map('intval', $b['game']));
        }
        foreach ($b['category'] as $category) {
            match ($category) {
                'live' => $query->where("$t.is_live", false),
                'mini' => $query->where(fn ($q) => $q->whereNull("$t.category")->orWhere("$t.category", '!=', 'mini')),
                'slot' => $query->where(fn ($q) => $q->where("$t.is_live", true)->orWhereIn("$t.category", ['mini', 'virtual'])),
                default => null,
            };
        }

        return $query;
    }

    public function isPlayable(CasinoGame $game, ?User $user): bool
    {
        $b = $this->blocked(self::scopeIdsFor($user));

        if (in_array((string) $game->provider?->code, $b['provider'], true)) {
            return false;
        }
        if ($game->vendor !== null && in_array($game->vendor, $b['vendor'], true)) {
            return false;
        }
        if (in_array((string) $game->id, $b['game'], true)) {
            return false;
        }

        return ! in_array(self::categoryOf($game), $b['category'], true);
    }

    /**
     * Ham katalog satırı bu izleyiciye açık mı? Önbellekteki listeye bayi engeli yazılmaz; her istekte burada elenir.
     *
     * @param  array{id: int, provider?: ?string, vendor?: ?string, category?: ?string, is_live?: bool}  $game
     */
    public function visible(array $game, ?User $user): bool
    {
        $b = $this->blocked(self::scopeIdsFor($user));

        $provider = (string) ($game['provider'] ?? '');
        if ($provider !== '' && in_array($provider, $b['provider'], true)) {
            return false;
        }
        $vendor = $game['vendor'] ?? null;
        if (is_string($vendor) && $vendor !== '' && in_array($vendor, $b['vendor'], true)) {
            return false;
        }
        if (in_array((string) $game['id'], $b['game'], true)) {
            return false;
        }

        $category = ! empty($game['is_live']) ? 'live' : (($game['category'] ?? null) === 'mini' ? 'mini' : 'slot');

        return ! in_array($category, $b['category'], true);
    }

    /** Bu üyenin ağacında kapatılmış spor dalları. */
    public static function closedSports(?User $user): array
    {
        $closed = app(self::class)->blocked(self::scopeIdsFor($user))['sport'] ?? [];

        return array_values(array_intersect($closed, self::SPORTS));
    }

    /** Urun (or. Wegas Spor) bu kullanicinin yolunda bir yerde kapatilmis mi? */
    public static function productBlocked(?User $user, string $product): bool
    {
        return in_array($product, app(self::class)->blocked(self::scopeIdsFor($user))['product'] ?? [], true);
    }

    public static function categoryOf(CasinoGame $game): string
    {
        if ($game->is_live) {
            return 'live';
        }

        return $game->category === 'mini' ? 'mini' : 'slot';
    }

    /** Engel eklenince/kaldirilinca cagrilir; engel sürümü ve ham liste anahtarları hemen düşer. */
    public function flush(): void
    {
        Cache::forever(self::VERSION_KEY, (int) Cache::get(self::VERSION_KEY, 1) + 1);
        app(CatalogCache::class)->forget();
    }

    public static function cacheVersion(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 1);
    }
}

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
    public const SCOPES = ['provider', 'vendor', 'category', 'game'];

    public const CATEGORIES = ['slot', 'live', 'mini'];

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

    /** @return array<string, list<string>> genel + (varsa) superadmin engelleri */
    public function blocked(?int $superadminId): array
    {
        $version = (int) Cache::get(self::VERSION_KEY, 1);

        return Cache::remember("casino:game_blocks:{$version}:".($superadminId ?? 'g'), 600, function () use ($superadminId) {
            $rows = GameBlock::query()
                ->where(function ($q) use ($superadminId) {
                    $q->whereNull('superadmin_id');
                    if ($superadminId !== null) {
                        $q->orWhere('superadmin_id', $superadminId);
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
        $b = $this->blocked(self::superadminIdFor($user));
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
        $b = $this->blocked(self::superadminIdFor($user));

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

    public static function categoryOf(CasinoGame $game): string
    {
        if ($game->is_live) {
            return 'live';
        }

        return $game->category === 'mini' ? 'mini' : 'slot';
    }

    /** Engel eklenince/kaldirilinca cagrilir; tum onbellek anahtarlari gecersiz olur. */
    public function flush(): void
    {
        Cache::forever(self::VERSION_KEY, (int) Cache::get(self::VERSION_KEY, 1) + 1);
    }
}

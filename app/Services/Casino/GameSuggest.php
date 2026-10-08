<?php

namespace App\Services\Casino;

use App\Models\CasinoGame;
use App\Models\User;
use App\Services\GameImages;
use App\Support\GameSearch;
use App\Support\Vendors;

/** Yazarken oyun araması. Yanıt düz dizidir; Collection önbelleğe yazılmaz. */
class GameSuggest
{
    public const LIMIT = 8;

    public function __construct(private readonly GameAvailability $availability) {}

    /**
     * @return array{total: int, games: list<array{id: int, name: string, provider: ?string, image: ?string, href: string}>}
     */
    public function search(?User $user, string $mode, string $term, string $vendor = ''): array
    {
        $term = trim($term);
        if (mb_strlen($term) < 2) {
            return ['total' => 0, 'games' => []];
        }
        if (! in_array($mode, ['slot', 'live', 'mini'], true)) {
            $mode = 'slot';
        }

        $query = CasinoGame::query()
            ->with('provider')
            ->where('is_active', true)
            ->whereHas('provider', fn ($query) => $query->where('status', 'active'))
            ->where('name_folded', 'like', GameSearch::like($term));

        $this->availability->apply($query, $user);

        match ($mode) {
            'live' => $query->where('is_live', true),
            'mini' => $query->where('category', 'mini'),
            default => $query->where('is_live', false)->where(fn ($q) => $q->whereNull('category')->orWhereNotIn('category', ['virtual', 'mini'])),
        };

        if ($vendor !== '') {
            $query->where('vendor', $vendor);
        }

        $total = (clone $query)->count();
        $games = $query
            ->orderByDesc('is_popular')
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get();

        $images = app(GameImages::class);

        return [
            'total' => $total,
            'games' => $games->map(function (CasinoGame $game) use ($images, $user) {
                return [
                    'id' => $game->id,
                    'name' => $game->name,
                    'provider' => $this->provider($game),
                    'image' => $game->image_url ? $images->url($game) : null,
                    'href' => $user !== null ? route('site.launch', $game) : route('login'),
                ];
            })->values()->all(),
        ];
    }

    private function provider(CasinoGame $game): ?string
    {
        $label = Vendors::name($game->vendor);
        if ($label !== null && ! $this->aggregator($label)) {
            return $label;
        }
        $name = $game->provider?->name;

        return $name !== null && ! $this->aggregator($name) ? $name : null;
    }

    private function aggregator(string $name): bool
    {
        return in_array(mb_strtolower($name), ['romaspin', 'goldpalace', '1gamex', 'onegamex'], true);
    }
}

<?php

namespace App\Services;

use App\Models\CasinoGame;
use App\Models\HomeSlide;
use App\Models\User;
use App\Services\Casino\GameAvailability;
use App\Support\Vendors;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Ana sayfa slaytı: panel kayıtları, son 24 saatin en yüksek kazancı, günün maçı. */
class HomeSlides
{
    private const CACHE = 'home:slides:rows';

    /** @var array<string, string> */
    private const PINNED = [
        'sweet-bonanza-2500' => 'Sweet Bonanza 2500',
        'sweet-bonanza-super-scatter' => 'Sweet Bonanza Super Scatter',
    ];

    public function __construct(private readonly GameAvailability $availability) {}

    /**
     * @param  array<string, mixed>|null  $featured
     * @return list<array<string, mixed>>
     */
    public function forViewer(?User $user, ?array $featured): array
    {
        $rows = $this->rows();
        $games = $this->gamesFor($rows);
        $used = [];
        $slides = [];

        foreach ($rows as $row) {
            if (! $row['is_active']) {
                continue;
            }
            if ($row['key'] === HomeSlide::MATCH) {
                $slide = $this->matchSlide($user, $featured);
                if ($slide !== null) {
                    $slides[] = $slide;
                }
                continue;
            }

            $game = $row['key'] === HomeSlide::TOP_WIN
                ? $this->topWin($user, $games, $used)
                : $games->get($row['game_id']);

            if (! $game instanceof CasinoGame || isset($used[$game->id]) || ! $this->showGame($game, $user)) {
                continue;
            }
            $used[$game->id] = true;
            $slides[] = $this->gameSlide($game, $row['image_path'] ? route('site.home_slide.image', $row['id']) : null);
        }

        if ($slides !== []) {
            $slides[0]['eager'] = true;
        }

        return $slides;
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE);
    }

    public function ensureDefaults(): void
    {
        $order = 10;
        foreach (self::PINNED as $key => $name) {
            $slide = HomeSlide::query()->firstOrCreate(['key' => $key], [
                'game_id' => $this->findNamed($name)?->id,
                'sort_order' => $order,
                'is_active' => true,
            ]);
            if ($slide->game_id === null) {
                $game = $this->findNamed($name);
                if ($game !== null) {
                    $slide->game_id = $game->id;
                    $slide->save();
                    $this->forget();
                }
            }
            $order += 10;
        }
        HomeSlide::query()->firstOrCreate(['key' => HomeSlide::TOP_WIN], ['sort_order' => $order, 'is_active' => true]);
        HomeSlide::query()->firstOrCreate(['key' => HomeSlide::MATCH], ['sort_order' => $order + 10, 'is_active' => true]);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function gamesFor(array $rows)
    {
        $ids = array_values(array_filter(array_map(fn ($row) => $row['game_id'], $rows)));
        $ids = array_values(array_unique([...$ids, ...$this->topWinIds()]));

        if ($ids === []) {
            return collect();
        }

        return CasinoGame::query()->with('provider')->whereIn('id', $ids)->get()->keyBy('id');
    }

    /**
     * @param  \Illuminate\Support\Collection<int, CasinoGame>  $games
     * @param  array<int, true>  $used
     */
    private function topWin(?User $user, $games, array $used): ?CasinoGame
    {
        foreach ($this->topWinIds() as $id) {
            $game = $games->get($id);
            if ($game instanceof CasinoGame && ! isset($used[$game->id]) && $this->showGame($game, $user)) {
                return $game;
            }
        }

        return null;
    }

    /**
     * @return list<int>
     */
    private function topWinIds(): array
    {
        $ids = Cache::remember('home:top-wins:'.now()->toDateString(), 86400, function () {
            return DB::table('game_rounds as r')
                ->join('casino_games as g', 'g.id', '=', 'r.game_id')
                ->where('r.status', 'win')
                ->where('r.win', '>', 0)
                ->where('r.created_at', '>=', now()->subDay())
                ->where('g.is_active', true)
                ->where(fn ($q) => $q->where('g.is_live', true)->orWhere('g.category', 'mini')->orWhere(fn ($slot) => $slot->where('g.is_live', false)->where(fn ($w) => $w->whereNull('g.category')->orWhere('g.category', '!=', 'virtual'))))
                ->groupBy('r.game_id')
                ->selectRaw('r.game_id as game_id, SUM(r.win) as payout')
                ->orderByDesc('payout')
                ->limit(30)
                ->pluck('game_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        });

        return is_array($ids) ? $ids : [];
    }

    private function showGame(CasinoGame $game, ?User $user): bool
    {
        if (! $game->is_active || $game->provider?->status !== 'active' || $game->category === 'virtual') {
            return false;
        }

        return $this->availability->isPlayable($game, $user);
    }

    /**
     * @return array<string, mixed>
     */
    private function gameSlide(CasinoGame $game, ?string $customImage): array
    {
        return [
            'type' => 'game',
            'name' => $game->name,
            'provider' => Vendors::name($game->vendor) ?? $game->provider?->name,
            'image' => $customImage ?: app(GameImages::class)->url($game),
            'href' => auth()->check() ? route('site.launch', $game) : route('login'),
            'eager' => false,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $featured
     * @return array<string, mixed>|null
     */
    private function matchSlide(?User $user, ?array $featured): ?array
    {
        if ($featured === null || ! wegas_sport_available($user)) {
            return null;
        }

        return [
            'type' => 'match',
            'featured' => $featured,
            'eager' => false,
        ];
    }

    /**
     * @return list<array{id: int, key: ?string, game_id: ?int, is_active: bool, image_path: ?string}>
     */
    private function rows(): array
    {
        $this->ensureDefaults();

        $rows = Cache::remember(self::CACHE, 900, function () {
            return HomeSlide::query()->orderBy('sort_order')->orderBy('id')->get()->map(fn (HomeSlide $slide) => [
                'id' => (int) $slide->id,
                'key' => $slide->key,
                'game_id' => $slide->game_id === null ? null : (int) $slide->game_id,
                'is_active' => (bool) $slide->is_active,
                'image_path' => $slide->image_path,
            ])->all();
        });

        return is_array($rows) ? $rows : [];
    }

    private function findNamed(string $name): ?CasinoGame
    {
        return CasinoGame::query()
            ->where('name', $name)
            ->where('is_active', true)
            ->where('is_live', false)
            ->whereHas('provider', fn ($q) => $q->where('status', 'active'))
            ->orderBy('id')
            ->first();
    }
}

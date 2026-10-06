<?php

namespace App\Services;

use App\Models\CasinoGame;
use App\Models\HomeSlide;
use App\Models\User;
use App\Services\Casino\GameAvailability;
use App\Services\Casino\HomeCasinoRails;
use App\Support\Vendors;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Ana sayfa slaytı: sabit oyunlar, son 24 saatin en yüksek kazancı, panel oyunları, popüler doldurma. */
class HomeSlides
{
    public const MIN = 6;

    private const CACHE = 'home:slides:rows';

    public function __construct(
        private readonly GameAvailability $availability,
        private readonly HomeCasinoRails $rails,
        private readonly GameImages $images,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function forViewer(?User $user): array
    {
        $rows = $this->rows();
        $games = $this->gamesFor($rows);
        $suppressed = [];
        $reserved = array_fill_keys(array_values(HomeSlide::PINNED), true);
        foreach ($rows as $row) {
            if (! $row['is_active'] && $row['game_id'] !== null) {
                $suppressed[(int) $row['game_id']] = true;
            }
        }

        $used = [];
        $names = [];
        $slides = [];
        foreach ($this->ordered($rows) as $row) {
            if (! $row['is_active']) {
                continue;
            }
            $game = $row['key'] === HomeSlide::TOP_WIN
                ? $this->topWin($user, $games, $used, $names, $suppressed)
                : $this->take($games->get($row['game_id']), $user, $used, $names, $suppressed);
            if ($game instanceof CasinoGame) {
                $slides[] = $this->gameSlide($game, $row['image_path'] ? route('site.home_slide.image', $row['id']) : null);
            }
        }

        if (count($slides) < self::MIN) {
            foreach ($this->rails->popularSlots($user, self::MIN + count($used) + count($suppressed) + 12) as $game) {
                if (isset($reserved[$game->name])) {
                    continue;
                }
                $picked = $this->take($game, $user, $used, $names, $suppressed);
                if (! $picked instanceof CasinoGame) {
                    continue;
                }
                $slides[] = $this->gameSlide($picked, null);
                if (count($slides) >= self::MIN) {
                    break;
                }
            }
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
        if (! HomeSlide::query()->exists()) {
            $order = 10;
            foreach (HomeSlide::PINNED as $key => $name) {
                HomeSlide::query()->firstOrCreate(['key' => $key], [
                    'game_id' => $this->findNamed($name)?->id,
                    'sort_order' => $order,
                    'is_active' => true,
                ]);
                $order += 10;
            }
            HomeSlide::query()->firstOrCreate(['key' => HomeSlide::TOP_WIN], [
                'sort_order' => $order,
                'is_active' => true,
            ]);
        }

        foreach (HomeSlide::PINNED as $key => $name) {
            $slide = HomeSlide::query()->where('key', $key)->first();
            if ($slide === null || $slide->game_id !== null) {
                continue;
            }
            $game = $this->findNamed($name);
            if ($game === null) {
                continue;
            }
            $slide->game_id = $game->id;
            $slide->save();
            $this->forget();
        }
    }

    /**
     * @param  list<array{id: int, key: ?string, game_id: ?int, is_active: bool, image_path: ?string}>  $rows
     * @return list<array{id: int, key: ?string, game_id: ?int, is_active: bool, image_path: ?string}>
     */
    private function ordered(array $rows): array
    {
        $pinned = [];
        $rest = [];
        foreach ($rows as $row) {
            if ($row['key'] !== null && array_key_exists($row['key'], HomeSlide::PINNED)) {
                $pinned[] = $row;
            } else {
                $rest[] = $row;
            }
        }

        return [...$pinned, ...$rest];
    }

    /**
     * @param  list<array{id: int, key: ?string, game_id: ?int, is_active: bool, image_path: ?string}>  $rows
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
     * @param  array<string, true>  $names
     * @param  array<int, true>  $suppressed
     */
    private function topWin(?User $user, $games, array &$used, array &$names, array $suppressed): ?CasinoGame
    {
        foreach ($this->topWinIds() as $id) {
            $game = $this->take($games->get($id), $user, $used, $names, $suppressed);
            if ($game instanceof CasinoGame) {
                return $game;
            }
        }

        return null;
    }

    /**
     * @param  array<int, true>  $used
     * @param  array<string, true>  $names
     * @param  array<int, true>  $suppressed
     */
    private function take(mixed $game, ?User $user, array &$used, array &$names, array $suppressed): ?CasinoGame
    {
        if (! $game instanceof CasinoGame || isset($used[$game->id]) || isset($names[$game->name]) || isset($suppressed[$game->id]) || ! $this->showGame($game, $user)) {
            return null;
        }
        $used[$game->id] = true;
        $names[$game->name] = true;

        return $game;
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
            'image' => $customImage ?: $this->images->url($game),
            'href' => auth()->check() ? route('site.launch', $game) : route('login'),
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

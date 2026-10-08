<?php

namespace App\Services;

use App\Enums\Currency;
use App\Models\CasinoGame;
use App\Models\HomeSlide;
use App\Models\User;
use App\Services\Casino\GameAvailability;
use App\Services\Casino\HomeCasinoRails;
use App\Support\Money;
use App\Support\Vendors;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Ana sayfa slaytı: sabit oyunlar, son 24 saatin en yüksek kazancı, panel oyunları, popüler doldurma. Canlı ve mini slaytlar config/home.php slides listesinden gelir. */
class HomeSlides
{
    public const MIN = 6;

    public const MAX = 12;

    private const CACHE = 'home:slides:rows';

    private Currency $currency = Currency::Try;

    public function __construct(
        private readonly GameAvailability $availability,
        private readonly HomeCasinoRails $rails,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function forViewer(?User $user): array
    {
        return $this->compose($user);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function compose(?User $user): array
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
        $this->currency = $user?->currency ?? Currency::Try;
        $yesterday = $this->yesterdayTotals($user);
        foreach ($this->ordered($rows) as $row) {
            if (! $row['is_active']) {
                continue;
            }
            $winner = $row['key'] === HomeSlide::TOP_WIN;
            $game = $winner
                ? $this->topWin($user, $games, $used, $names, $suppressed)
                : $this->take($games->get($row['game_id']), $user, $used, $names, $suppressed);
            if ($game instanceof CasinoGame) {
                $slides[] = $this->gameSlide($game, $row['image_path'] ? route('site.home_slide.image', $row['id']) : null, $winner, $yesterday);
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
                $slides[] = $this->gameSlide($picked, null, false, $yesterday);
                if (count($slides) >= self::MIN) {
                    break;
                }
            }
        }

        $buckets = ['slot' => [], 'live' => [], 'mini' => []];
        $usedIds = [];
        foreach ($slides as $slide) {
            $category = $slide['category'] ?? 'slot';
            if (! isset($buckets[$category])) {
                $category = 'slot';
            }
            $buckets[$category][] = $slide;
            if (isset($slide['game_id'])) {
                $usedIds[(int) $slide['game_id']] = true;
            }
        }
        foreach ($this->configured($user, $usedIds, $yesterday) as $slide) {
            $buckets[$slide['category']][] = $slide;
        }

        $woven = $this->weave($buckets);
        if ($woven !== []) {
            $woven[0]['eager'] = true;
        }

        return $woven;
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE);
        Cache::forever('home:slides:view-version', ((int) Cache::get('home:slides:view-version', 1)) + 1);
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
     * @param  Collection<int, CasinoGame>  $games
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
     * Özel banner varsa o kullanılır. Yoksa sağlayıcı görseli; 584x438 küçük kare
     * büyütülmez, CDN'deki 800 / 1000 / 1200 karşılığı srcset ile verilir.
     *
     * @return array{src: ?string, srcset: ?string}
     */
    private function slideArt(CasinoGame $game, ?string $customImage): array
    {
        if ($customImage !== null && $customImage !== '') {
            return ['src' => $customImage, 'srcset' => null];
        }

        $url = $game->image_url;
        if (! is_string($url) || $url === '' || ! preg_match('/_584x438_NB(\.[a-z0-9]+)$/i', $url, $match)) {
            return ['src' => $url ?: null, 'srcset' => null];
        }

        $base = preg_replace('/_584x438_NB\.[a-z0-9]+$/i', '', $url);
        $ext = $match[1];
        $medium = $base.'_800x600_NB'.$ext;
        $square = $base.'_1000x1000_NB'.$ext;

        return [
            'src' => $square,
            'srcset' => $medium.' 800w, '.$square.' 1000w',
        ];
    }

    /**
     * Dünün oyun kazançları. Önbellek düz dizi: game_id => tutar. Collection yazılmaz.
     *
     * @return array<int, string>
     */
    private function yesterdayTotals(?User $user): array
    {
        $zone = $this->zone($user);
        $now = now($zone);
        $currency = $this->currency;
        $key = 'home:yesterday-wins:'.$zone.':'.$now->toDateString().':'.$currency->value;
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }
        if ($cached !== null) {
            Cache::forget($key);
        }

        $rows = DB::table('game_rounds as r')
            ->join('users as u', 'u.id', '=', 'r.user_id')
            ->where('r.win', '>', 0)
            ->where('u.currency', $currency->value)
            ->where('r.created_at', '>=', $now->copy()->subDay()->startOfDay()->utc())
            ->where('r.created_at', '<', $now->copy()->startOfDay()->utc())
            ->groupBy('r.game_id')
            ->selectRaw('r.game_id as game_id, SUM(r.win) as payout')
            ->pluck('payout', 'game_id')
            ->map(fn ($amount) => number_format((float) $amount, 2, '.', ''))
            ->all();

        Cache::put($key, $rows, 3600);

        return $rows;
    }

    private function zone(?User $user): string
    {
        $zone = $user?->timezone ?: 'Europe/Istanbul';
        try {
            now($zone);
        } catch (\Throwable) {
            return 'Europe/Istanbul';
        }

        return $zone;
    }

    /**
     * @param  array<int, string>  $yesterday
     * @return array<string, mixed>
     */
    private function gameSlide(CasinoGame $game, ?string $customImage, bool $winner = false, array $yesterday = []): array
    {
        $art = $this->slideArt($game, $customImage);
        $category = GameAvailability::categoryOf($game);
        $amount = $yesterday[$game->id] ?? $yesterday[(string) $game->id] ?? null;
        $won = is_string($amount) && bccomp($amount, '0', 2) === 1
            ? Money::format($amount, $this->currency)
            : null;

        return [
            'type' => 'game',
            'category' => $category,
            'game_id' => $game->id,
            'name' => $game->name,
            'provider' => $this->providerLabel($game),
            'image' => $art['src'],
            'srcset' => $art['srcset'],
            'href' => auth()->check() ? route('site.launch', $game) : route('login'),
            'cta' => $category === 'live' ? 'home.sit_down' : 'home.play_now',
            'winner' => $winner,
            'yesterday' => $won,
            'eager' => false,
        ];
    }

    /**
     * @param  array<int, true>  $usedIds
     * @param  array<int, string>  $yesterday
     * @return list<array<string, mixed>>
     */
    private function configured(?User $user, array $usedIds, array $yesterday): array
    {
        $specs = config('home.slides');
        if (! is_array($specs) || $specs === []) {
            return [];
        }

        $ids = [];
        foreach ($specs as $spec) {
            if (is_array($spec) && isset($spec['game_id'])) {
                $ids[] = (int) $spec['game_id'];
            }
        }
        $games = $ids === []
            ? collect()
            : CasinoGame::query()->with('provider')->whereIn('id', $ids)->get()->keyBy('id');

        $slides = [];
        foreach ($specs as $spec) {
            if (! is_array($spec)) {
                continue;
            }
            $category = (string) ($spec['category'] ?? '');
            if (! in_array($category, ['slot', 'live', 'mini'], true)) {
                continue;
            }
            $game = $games->get((int) ($spec['game_id'] ?? 0));
            if (! $game instanceof CasinoGame || isset($usedIds[$game->id]) || GameAvailability::categoryOf($game) !== $category || ! $this->showGame($game, $user)) {
                continue;
            }
            $slide = $this->gameSlide($game, $this->banner(isset($spec['image']) ? (string) $spec['image'] : null), false, $yesterday);
            if ($slide['image'] === null || $slide['image'] === '') {
                continue;
            }
            $label = trim((string) ($spec['provider_label'] ?? ''));
            if ($label !== '') {
                $slide['provider'] = $label;
            }
            $usedIds[$game->id] = true;
            $slides[] = $slide;
        }

        return $slides;
    }

    private function banner(?string $image): ?string
    {
        if ($image === null || $image === '') {
            return null;
        }
        if (str_starts_with($image, 'http://') || str_starts_with($image, 'https://')) {
            return $image;
        }
        $relative = ltrim($image, '/');

        return $relative !== '' && is_file(public_path($relative)) ? '/'.$relative : null;
    }

    private function providerLabel(CasinoGame $game): ?string
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

    /**
     * Tek kategori varsa sırası korunur. Birden fazla kategori karışınca aynı kategori
     * art arda gelmez ve toplam 12 slaytı geçmez.
     *
     * @param  array{slot: list<array<string, mixed>>, live: list<array<string, mixed>>, mini: list<array<string, mixed>>}  $buckets
     * @return list<array<string, mixed>>
     */
    private function weave(array $buckets): array
    {
        $order = ['slot', 'live', 'mini'];
        $active = array_values(array_filter($order, fn (string $key) => ($buckets[$key] ?? []) !== []));
        if (count($active) < 2) {
            $only = $active[0] ?? null;

            return $only === null ? [] : $buckets[$only];
        }

        $out = [];
        $last = null;
        while (count($out) < self::MAX) {
            $best = null;
            $bestCount = -1;
            foreach ($order as $key) {
                $count = count($buckets[$key] ?? []);
                if ($key === $last || $count === 0 || $count <= $bestCount) {
                    continue;
                }
                $best = $key;
                $bestCount = $count;
            }
            if ($best === null) {
                break;
            }
            $out[] = array_shift($buckets[$best]);
            $last = $best;
        }

        return $out;
    }

    /**
     * @return list<array{id: int, key: ?string, game_id: ?int, is_active: bool, image_path: ?string}>
     */
    private function rows(): array
    {
        $rows = Cache::get(self::CACHE);
        if (is_array($rows)) {
            return $rows;
        }

        $this->ensureDefaults();

        $rows = HomeSlide::query()->orderBy('sort_order')->orderBy('id')->get()->map(fn (HomeSlide $slide) => [
            'id' => (int) $slide->id,
            'key' => $slide->key,
            'game_id' => $slide->game_id === null ? null : (int) $slide->game_id,
            'is_active' => (bool) $slide->is_active,
            'image_path' => $slide->image_path,
        ])->all();
        Cache::put(self::CACHE, $rows, 600);

        return $rows;
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

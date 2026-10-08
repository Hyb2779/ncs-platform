<?php

namespace App\Services;

use App\Models\CasinoGame;
use App\Models\HomeSlide;
use App\Models\User;
use App\Services\Casino\GameAvailability;
use App\Support\Vendors;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Ana sayfa slaytı: 2 sabit, 3 yeni, 3 popüler. Havuzlar saatte bir yenilenir; seçim önbellekten yapılır. */
class HomeSlides
{
    public const MIN = 6;

    public const MAX = 8;

    private const ROWS = 'home:slides:rows:v2';

    private const POOL_NEW = 'home:slider:pool:new';

    private const POOL_POPULAR = 'home:slider:pool:popular';

    private const POOL_TTL = 3600;

    private const POOL_LIMIT = 80;

    public function __construct(
        private readonly GameAvailability $availability,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function forViewer(?User $user): array
    {
        $rows = $this->rows();
        $excluded = [];
        $fixed = [];
        foreach ($rows as $row) {
            if ($row['excluded'] && $row['game_id'] !== null) {
                $excluded[$row['game_id']] = true;
            }
            if ($row['is_active'] && $row['key'] !== null && array_key_exists($row['key'], HomeSlide::PINNED) && is_array($row['game'])) {
                $fixed[] = $row;
            }
        }
        shuffle($fixed);

        $usedIds = [];
        $slides = [];
        $categories = [];
        foreach ($fixed as $row) {
            if (isset($excluded[$row['game']['id']]) || ! $this->allowed($row['game'], $user)) {
                continue;
            }
            $slide = $this->slideFrom($row['game'], false, $row['image_path'] ? route('site.home_slide.image', $row['id']) : null);
            if ($slide === null) {
                continue;
            }
            $slides[] = $slide;
            $usedIds[$slide['game_id']] = true;
            $categories[$slide['category']] = true;
            if (count($slides) >= 2) {
                break;
            }
        }

        $seen = $this->seen();
        $usedProviders = [];
        $open = self::MAX - count($slides);
        $newCount = min(3, $open);
        $popularCount = min(3, $open - $newCount);
        $extra = $open - $newCount - $popularCount;
        $pickedNew = $this->draw($this->pool(self::POOL_NEW, false), $newCount, $seen, $usedIds, $usedProviders, $categories, $excluded, $user);
        foreach ($pickedNew as $game) {
            $slide = $this->slideFrom($game, true, null);
            if ($slide !== null) {
                $slides[] = $slide;
            }
        }
        $pickedPopular = $this->draw($this->pool(self::POOL_POPULAR, true), $popularCount + $extra, $seen, $usedIds, $usedProviders, $categories, $excluded, $user);
        foreach ($pickedPopular as $game) {
            $slide = $this->slideFrom($game, false, null);
            if ($slide !== null) {
                $slides[] = $slide;
            }
        }

        if (count($slides) < self::MAX) {
            foreach ($this->draw($this->pool(self::POOL_NEW, false), self::MAX - count($slides), $seen, $usedIds, $usedProviders, $categories, $excluded, $user) as $game) {
                $slide = $this->slideFrom($game, true, null);
                if ($slide !== null) {
                    $slides[] = $slide;
                }
            }
        }

        shuffle($slides);
        if ($slides !== []) {
            $slides[0]['eager'] = true;
        }
        session(['home.slider.last' => array_values(array_map(fn (array $slide) => (int) $slide['game_id'], $slides))]);

        return $slides;
    }

    public function forget(): void
    {
        Cache::forget(self::ROWS);
        Cache::forget(self::POOL_NEW);
        Cache::forget(self::POOL_POPULAR);
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
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        $rows = Cache::get(self::ROWS);
        if (is_array($rows)) {
            return $rows;
        }

        $this->ensureDefaults();

        $loaded = HomeSlide::query()->with('game.provider')->orderBy('sort_order')->orderBy('id')->get();
        $rows = [];
        foreach ($loaded as $slide) {
            $rows[] = [
                'id' => (int) $slide->id,
                'key' => $slide->key,
                'game_id' => $slide->game_id === null ? null : (int) $slide->game_id,
                'is_active' => (bool) $slide->is_active,
                'excluded' => (bool) $slide->excluded,
                'image_path' => $slide->image_path,
                'game' => $this->snapshot($slide->game),
            ];
        }
        Cache::put(self::ROWS, $rows, 600);

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pool(string $key, bool $popular): array
    {
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }
        if ($cached !== null) {
            Cache::forget($key);
        }

        $rows = [];
        foreach (['slot' => 40, 'live' => 25, 'mini' => 15] as $category => $limit) {
            foreach ($this->poolQuery($category, $popular, $limit) as $row) {
                $snap = $this->snapshotRow($row);
                if ($snap !== null) {
                    $rows[] = $snap;
                }
            }
        }
        $rows = array_slice($rows, 0, self::POOL_LIMIT);
        Cache::put($key, $rows, self::POOL_TTL);

        return $rows;
    }

    /**
     * @return list<object>
     */
    private function poolQuery(string $category, bool $popular, int $limit): array
    {
        $query = DB::table('casino_games as g')
            ->join('casino_providers as p', 'p.id', '=', 'g.provider_id')
            ->where('g.is_active', true)
            ->where('p.status', 'active')
            ->whereNotNull('g.image_url')
            ->where('g.image_url', '!=', '')
            ->where(function ($q) {
                $q->whereNull('g.category')->orWhere('g.category', '!=', 'virtual');
            });
        match ($category) {
            'live' => $query->where('g.is_live', true),
            'mini' => $query->where('g.is_live', false)->where('g.category', 'mini'),
            default => $query->where('g.is_live', false)->where(function ($q) {
                $q->whereNull('g.category')->orWhere('g.category', '!=', 'mini');
            }),
        };
        if ($popular) {
            $query->where('g.is_popular', true);
        }
        $query->orderByDesc('g.id')->limit($limit);

        return $query->get([
            'g.id', 'g.name', 'g.provider_id', 'g.vendor', 'g.category', 'g.is_live', 'g.is_active', 'g.image_url',
            'p.code as provider_code', 'p.name as provider_name', 'p.status as provider_status',
        ])->all();
    }

    /**
     * @param  list<array<string, mixed>>  $pool
     * @param  array<int, true>  $seen
     * @param  array<int, true>  $usedIds
     * @param  array<string, true>  $usedProviders
     * @param  array<string, true>  $categories
     * @param  array<int, true>  $excluded
     * @return list<array<string, mixed>>
     */
    private function draw(array $pool, int $count, array $seen, array &$usedIds, array &$usedProviders, array &$categories, array $excluded, ?User $user): array
    {
        if ($count < 1) {
            return [];
        }
        $fresh = [];
        $stale = [];
        $providers = $usedProviders;
        foreach ($pool as $game) {
            if (! is_array($game) || isset($usedIds[$game['id']]) || isset($excluded[$game['id']]) || ! $this->allowed($game, $user)) {
                continue;
            }
            $key = $this->providerKey($game);
            if (isset($providers[$key])) {
                continue;
            }
            $providers[$key] = true;
            if (isset($seen[$game['id']])) {
                $stale[] = $game;
            } else {
                $fresh[] = $game;
            }
        }
        shuffle($fresh);
        shuffle($stale);
        $queue = [...$fresh, ...$stale];
        $chosen = [];
        $taken = [];
        foreach (['slot', 'live', 'mini'] as $category) {
            if (count($chosen) >= $count || isset($categories[$category])) {
                continue;
            }
            foreach ($queue as $game) {
                if (isset($taken[$game['id']]) || $game['category_key'] !== $category || isset($taken[$this->providerKey($game)])) {
                    continue;
                }
                $chosen[] = $game;
                $taken[$game['id']] = true;
                $taken[$this->providerKey($game)] = true;
                $usedProviders[$this->providerKey($game)] = true;
                $categories[$category] = true;
                break;
            }
        }
        foreach ($queue as $game) {
            if (count($chosen) >= $count) {
                break;
            }
            $key = $this->providerKey($game);
            if (isset($taken[$game['id']]) || isset($taken[$key]) || isset($usedProviders[$key])) {
                continue;
            }
            $chosen[] = $game;
            $taken[$game['id']] = true;
            $taken[$key] = true;
            $usedProviders[$key] = true;
            $categories[$game['category_key']] = true;
        }
        foreach ($chosen as $game) {
            $usedIds[$game['id']] = true;
        }

        return $chosen;
    }

    /**
     * @return array<int, true>
     */
    private function seen(): array
    {
        $raw = session('home.slider.last', []);
        if (! is_array($raw)) {
            return [];
        }
        $ids = [];
        foreach ($raw as $id) {
            $ids[(int) $id] = true;
        }

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $game
     */
    private function allowed(array $game, ?User $user): bool
    {
        if (! ($game['is_active'] ?? false) || ($game['provider_status'] ?? '') !== 'active' || ($game['category'] ?? '') === 'virtual') {
            return false;
        }
        if ($this->artUrl(isset($game['image_url']) ? (string) $game['image_url'] : null) === null) {
            return false;
        }

        return $this->availability->visible([
            'id' => (int) $game['id'],
            'provider' => (string) ($game['provider_code'] ?? ''),
            'vendor' => $game['vendor'] ?? null,
            'category' => $game['category'] ?? null,
            'is_live' => (bool) ($game['is_live'] ?? false),
        ], $user);
    }

    /**
     * @param  array<string, mixed>  $game
     * @return array<string, mixed>|null
     */
    private function slideFrom(array $game, bool $fresh, ?string $customImage): ?array
    {
        $image = $customImage !== null && $customImage !== '' ? $customImage : $this->artUrl((string) ($game['image_url'] ?? ''));
        if ($image === null || $image === '') {
            return null;
        }
        $category = (string) ($game['category_key'] ?? 'slot');

        return [
            'type' => 'game',
            'category' => $category,
            'game_id' => (int) $game['id'],
            'name' => (string) $game['name'],
            'provider' => $this->labelFrom($game),
            'provider_key' => $this->providerKey($game),
            'image' => $image,
            'href' => auth()->check() ? route('site.launch', $game['id']) : route('login'),
            'cta' => $category === 'live' ? 'home.sit_down' : 'home.play_now',
            'fresh' => $fresh,
            'eager' => false,
        ];
    }

    private function snapshot(?CasinoGame $game): ?array
    {
        if ($game === null) {
            return null;
        }

        return $this->snapshotRow((object) [
            'id' => $game->id,
            'name' => $game->name,
            'provider_id' => $game->provider_id,
            'vendor' => $game->vendor,
            'category' => $game->category,
            'is_live' => $game->is_live,
            'is_active' => $game->is_active,
            'image_url' => $game->image_url,
            'provider_code' => $game->provider?->code,
            'provider_name' => $game->provider?->name,
            'provider_status' => $game->provider?->status,
        ]);
    }

    private function snapshotRow(object $row): ?array
    {
        $url = isset($row->image_url) ? trim((string) $row->image_url) : '';
        if ($url === '') {
            return null;
        }
        $live = (bool) $row->is_live;
        $category = $live ? 'live' : ($row->category === 'mini' ? 'mini' : 'slot');

        return [
            'id' => (int) $row->id,
            'name' => (string) $row->name,
            'provider_id' => (int) $row->provider_id,
            'vendor' => $row->vendor !== null ? (string) $row->vendor : null,
            'category' => $row->category !== null ? (string) $row->category : null,
            'category_key' => $category,
            'is_live' => $live,
            'is_active' => (bool) $row->is_active,
            'image_url' => $url,
            'provider_code' => (string) ($row->provider_code ?? ''),
            'provider_name' => (string) ($row->provider_name ?? ''),
            'provider_status' => (string) ($row->provider_status ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $game
     */
    private function providerKey(array $game): string
    {
        $label = $this->labelFrom($game);

        return $label !== null ? 'l:'.mb_strtolower($label) : 'p:'.(int) $game['provider_id'];
    }

    /**
     * @param  array<string, mixed>  $game
     */
    private function labelFrom(array $game): ?string
    {
        $label = Vendors::name(isset($game['vendor']) ? (string) $game['vendor'] : null);
        if ($label !== null && ! $this->aggregator($label)) {
            return $label;
        }
        $name = (string) ($game['provider_name'] ?? '');

        return $name !== '' && ! $this->aggregator($name) ? $name : null;
    }

    private function artUrl(?string $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }
        $url = trim($url);
        if (! preg_match('/_584x438_NB(\.[a-z0-9]+)$/i', $url, $match)) {
            return $url;
        }
        $base = preg_replace('/_584x438_NB\.[a-z0-9]+$/i', '', $url);

        return $base.'_800x600_NB'.$match[1];
    }

    private function aggregator(string $name): bool
    {
        return in_array(mb_strtolower($name), ['romaspin', 'goldpalace', '1gamex', 'onegamex'], true);
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

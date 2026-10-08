<?php

namespace App\Services;

use App\Models\CasinoGame;
use App\Models\HomeSlide;
use App\Models\User;
use App\Services\Casino\GameAvailability;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/** Ana sayfa kategori kartlarının arka plan görselleri. Kaynak config/home.php. */
class HomeCategoryImages
{
    public function __construct(
        private readonly GameAvailability $availability,
        private readonly GameImages $images,
    ) {}

    /**
     * @return array<string, array{src: ?string, srcset: ?string}>
     */
    public function urls(?User $user): array
    {
        $version = GameAvailability::cacheVersion();
        $ids = GameAvailability::scopeIdsFor($user);
        $scope = $ids === [] ? 'g' : implode('-', $ids);

        $cached = Cache::remember("home:category-images:{$version}:{$scope}", 600, fn () => $this->resolveAll($user));

        return is_array($cached) ? $cached : [];
    }

    /**
     * @return array<string, array{src: ?string, srcset: ?string}>
     */
    private function resolveAll(?User $user): array
    {
        $categories = config('home.categories');
        if (! is_array($categories)) {
            return [];
        }

        $urls = [];
        foreach ($categories as $key => $spec) {
            $urls[(string) $key] = is_array($spec) ? $this->resolve($spec, $user) : ['src' => null, 'srcset' => null];
        }

        return $urls;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array{src: ?string, srcset: ?string}
     */
    private function resolve(array $spec, ?User $user): array
    {
        if (isset($spec['file'])) {
            return $this->fileArt((string) $spec['file']);
        }

        foreach ($spec['slide_keys'] ?? [] as $key) {
            $url = $this->slideImage((string) $key, $spec, $user);
            if ($url !== null) {
                return ['src' => $url, 'srcset' => null];
            }
        }

        $game = $this->findGame($spec, $user);

        return ['src' => $game === null ? null : $this->images->url($game), 'srcset' => null];
    }

    /**
     * @return array{src: ?string, srcset: ?string}
     */
    private function fileArt(string $file): array
    {
        $relative = ltrim($file, '/');
        if ($relative === '' || ! is_file(public_path($relative))) {
            return ['src' => null, 'srcset' => null];
        }

        $src = '/'.$relative.'?v=2';
        $one = preg_replace('/(\.[a-z0-9]+)$/i', '@1x$1', $relative);
        if (! is_string($one) || ! is_file(public_path($one))) {
            return ['src' => $src, 'srcset' => null];
        }

        return [
            'src' => $src,
            'srcset' => '/'.$one.'?v=2 800w, /'.$relative.'?v=2 1600w',
        ];
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function slideImage(string $key, array $spec, ?User $user): ?string
    {
        $slide = HomeSlide::query()->with('game.provider')->where('key', $key)->where('is_active', true)->first();
        if ($slide === null) {
            return null;
        }

        if ($slide->image_path && Storage::disk('public')->exists($slide->image_path)) {
            return route('site.home_slide.image', $slide);
        }

        $game = $slide->game;
        if (! $game instanceof CasinoGame || ! $this->matches($game, $spec) || ! $this->availability->isPlayable($game, $user)) {
            return null;
        }

        return $this->images->url($game);
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function findGame(array $spec, ?User $user): ?CasinoGame
    {
        $names = array_values(array_filter(array_map('strval', $spec['names'] ?? [])));
        foreach ($names as $name) {
            $game = $this->query($spec, $user)->where('name', $name)->first();
            if ($game instanceof CasinoGame) {
                return $game;
            }
        }

        if ($names !== []) {
            return null;
        }

        $game = $this->query($spec, $user)
            ->orderByDesc('is_popular')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        return $game instanceof CasinoGame ? $game : null;
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function query(array $spec, ?User $user)
    {
        $query = CasinoGame::query()
            ->with('provider')
            ->where('is_active', true)
            ->whereNotNull('image_url')
            ->where('image_url', '!=', '')
            ->whereHas('provider', function ($provider) use ($spec): void {
                $provider->where('status', 'active');
                if (! empty($spec['provider'])) {
                    $provider->where('code', (string) $spec['provider']);
                }
            });

        if (! empty($spec['live'])) {
            $query->where('is_live', true);
        }

        if (! empty($spec['category'])) {
            $query->where('category', (string) $spec['category']);
        }

        return $this->availability->apply($query, $user);
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function matches(CasinoGame $game, array $spec): bool
    {
        if (! $game->is_active || $game->image_url === null || $game->image_url === '' || $game->provider?->status !== 'active') {
            return false;
        }

        if (! empty($spec['provider']) && $game->provider?->code !== (string) $spec['provider']) {
            return false;
        }

        if (! empty($spec['live']) && ! $game->is_live) {
            return false;
        }

        if (! empty($spec['category']) && $game->category !== (string) $spec['category']) {
            return false;
        }

        return true;
    }
}

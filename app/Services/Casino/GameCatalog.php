<?php

namespace App\Services\Casino;

use App\Models\CasinoGame;
use App\Models\User;
use App\Support\Vendors;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Number;

/** Oyuncu footer'ı için aktif katalog: sayılar ve sağlayıcı pill'leri. */
class GameCatalog
{
    public const LICENSE_NO = '78542662-47885';

    public const COMPANY_NO = '78542670';

    /**
     * Aktif slot / canlı masa / sağlayıcı sayıları ve dolu sağlayıcı grupları.
     * Genel ve izleyicinin yolundaki engeller uygulanır; sonuç bir saat tutulur.
     *
     * @return array{
     *     slots: int,
     *     live: int,
     *     providers: int,
     *     groups: list<array{title: string, route: string, items: list<array{slug: string, name: string}>}>,
     *     license_no: string,
     *     company_no: string
     * }
     */
    public function footer(?User $user): array
    {
        $ids = GameAvailability::scopeIdsFor($user);
        $key = 'site:footer:v2:'.app()->getLocale().':'.GameAvailability::cacheVersion().':'.($ids === [] ? 'g' : implode('-', $ids));

        return Cache::remember($key, 3600, fn () => $this->build($user));
    }

    public static function domain(): string
    {
        $configured = config('domains.site');

        return filled($configured) ? (string) $configured : request()->getHost();
    }

    /** 1000+ yüzlüğe, 100+ onluğa aşağı yuvarlanır. */
    public static function rounded(int $count): int
    {
        if ($count >= 1000) {
            return intdiv($count, 100) * 100;
        }

        if ($count >= 100) {
            return intdiv($count, 10) * 10;
        }

        return $count;
    }

    public static function formatPlus(int $count): string
    {
        return Number::format(self::rounded($count), 0, locale: app()->getLocale()).'+';
    }

    /**
     * @return array{
     *     slots: int,
     *     live: int,
     *     providers: int,
     *     groups: list<array{title: string, route: string, items: list<array{slug: string, name: string}>}>,
     *     license_no: string,
     *     company_no: string
     * }
     */
    private function build(?User $user): array
    {
        $base = CasinoGame::query()
            ->where('casino_games.is_active', true)
            ->whereHas('provider', fn ($query) => $query->where('status', 'active'));
        app(GameAvailability::class)->apply($base, $user);

        $totals = (clone $base)->select([])->selectRaw(
            "SUM(CASE WHEN is_live = 0 AND (category IS NULL OR category NOT IN ('virtual', 'mini')) THEN 1 ELSE 0 END) AS slot_count, ".
            "SUM(CASE WHEN is_live = 1 THEN 1 ELSE 0 END) AS live_count"
        )->first();

        $rows = (clone $base)
            ->whereNotNull('vendor')
            ->where('vendor', '!=', '')
            ->select([])
            ->selectRaw(
                "vendor, ".
                "SUM(CASE WHEN is_live = 0 AND (category IS NULL OR category NOT IN ('virtual', 'mini')) THEN 1 ELSE 0 END) AS slot_count, ".
                "SUM(CASE WHEN is_live = 1 THEN 1 ELSE 0 END) AS live_count, ".
                "SUM(CASE WHEN category = 'mini' THEN 1 ELSE 0 END) AS mini_count"
            )
            ->groupBy('vendor')
            ->get();

        $buckets = ['slot' => [], 'live' => [], 'mini' => []];
        $brands = [];

        foreach ($rows as $row) {
            $slot = (int) $row->slot_count;
            $live = (int) $row->live_count;
            $mini = (int) $row->mini_count;
            if ($slot + $live + $mini === 0) {
                continue;
            }
            $slug = (string) $row->vendor;
            $item = ['slug' => $slug, 'name' => (string) Vendors::name($slug)];
            $brands[$this->brandKey(Vendors::canonical($slug))] = true;
            if ($slot > 0) {
                $buckets['slot'][] = $item;
            }
            if ($live > 0) {
                $buckets['live'][] = $item;
            }
            if ($mini > 0) {
                $buckets['mini'][] = $item;
            }
        }

        foreach ($buckets as &$items) {
            $items = $this->collapse($items);
            usort($items, fn (array $a, array $b) => [Vendors::priority($a['slug']), $a['name']] <=> [Vendors::priority($b['slug']), $b['name']]);
        }
        unset($items);

        $groups = [];
        foreach ([
            'slot' => ['site.footer_slots', 'site.slots'],
            'live' => ['site.live_casino', 'site.live_casino'],
            'mini' => ['site.footer_quick', 'site.mini'],
        ] as $key => [$title, $route]) {
            if ($buckets[$key] === []) {
                continue;
            }
            $groups[] = ['title' => $title, 'route' => $route, 'items' => $buckets[$key]];
        }

        return [
            'slots' => (int) ($totals->slot_count ?? 0),
            'live' => (int) ($totals->live_count ?? 0),
            'providers' => count($brands),
            'groups' => $groups,
            'license_no' => self::LICENSE_NO,
            'company_no' => self::COMPANY_NO,
        ];
    }

    /**
     * Aynı yazılan sağlayıcı bir kez kalır. Tanımlı ad, büyük harfe çevrilmiş kodun önüne geçer.
     *
     * @param  list<array{slug: string, name: string}>  $items
     * @return list<array{slug: string, name: string}>
     */
    private function collapse(array $items): array
    {
        $groups = [];
        foreach ($items as $item) {
            $groups[$this->brandKey(Vendors::canonical($item['slug']))][] = $item;
        }

        $out = [];
        foreach ($groups as $group) {
            $best = $group[0];
            foreach ($group as $item) {
                $named = array_key_exists($item['slug'], Vendors::NAMES);
                $bestNamed = array_key_exists($best['slug'], Vendors::NAMES);
                if ($named && (! $bestNamed || Vendors::priority($item['slug']) < Vendors::priority($best['slug']))) {
                    $best = $item;
                }
            }
            $best['name'] = (string) Vendors::name($best['slug']);
            $out[] = $best;
        }

        return $out;
    }

    private function brandKey(string $name): string
    {
        return (string) preg_replace('/[\s\-]+/u', '', mb_strtolower($name));
    }
}

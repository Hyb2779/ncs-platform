<?php

namespace App\Services\Sport;

use App\Models\SportFixture;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/** Ana sayfa canlı / yaklaşan maç şeridi. Kaynak Fenix (canlı yayın + senkron bülten). */
class HomeMatches
{
    /**
     * @return array{live: list<array<string, string>>, upcoming: list<array<string, string>>, tab: string, sport_url: string, live_url: string, refresh_url: string, refresh_ms: int}
     */
    public function present(?User $user): array
    {
        $zone = $this->zone($user);
        $locale = app()->getLocale();
        $ttl = max(5, (int) config('home.matches.live_ttl', 30));
        $cached = Cache::remember("home:matches:{$locale}:{$zone}", $ttl, function () use ($zone): array {
            return [
                'live' => $this->liveRows(),
                'upcoming' => $this->upcomingRows($zone),
            ];
        });
        $live = is_array($cached['live'] ?? null) ? $cached['live'] : [];
        $upcoming = is_array($cached['upcoming'] ?? null) ? $cached['upcoming'] : [];
        if (in_array('football', sport_closed($user), true)) {
            $live = [];
            $upcoming = [];
        }

        return [
            'live' => $live,
            'upcoming' => $upcoming,
            'tab' => $live !== [] ? 'live' : 'upcoming',
            'sport_url' => route(config('sport.own_book_enabled') ? 'site.sport' : 'site.wegas_sport'),
            'live_url' => config('sport.own_book_enabled')
                ? route('site.sport.live')
                : route('site.wegas_sport', ['live' => 1]),
            'refresh_url' => route('site.home.matches'),
            'refresh_ms' => (int) config('home.matches.refresh_ms', 30000),
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    private function liveRows(): array
    {
        return SportFixture::query()
            ->with(['home', 'away', 'league'])
            ->where('sport', 'football')
            ->whereIn('status', [...config('sport.live_statuses'), 'HT'])
            ->orderByRaw("case status when '1H' then 1 when '2H' then 2 when 'LIVE' then 3 when 'ET' then 4 when 'P' then 5 when 'BT' then 6 when 'HT' then 7 else 8 end")
            ->orderByDesc('elapsed')
            ->limit($this->limit())
            ->get()
            ->map(function (SportFixture $fixture): array {
                $clock = $fixture->status === 'HT'
                    ? __('sport.half_time')
                    : ($fixture->elapsed !== null ? sport_digits((string) $fixture->elapsed)."'" : '');

                return [
                    'home' => sport_name($fixture->home),
                    'away' => sport_name($fixture->away),
                    'league' => sport_name($fixture->league),
                    'badge' => __('site.live_badge'),
                    'url' => route('site.sport.show', $fixture),
                    'clock' => $clock,
                    'score' => sport_digits((string) ($fixture->score_home ?? '0')).' - '.sport_digits((string) ($fixture->score_away ?? '0')),
                ];
            })->all();
    }

    /**
     * @return list<array<string, string>>
     */
    private function upcomingRows(string $zone): array
    {
        $limit = $this->limit();
        $fixtures = SportFixture::query()
            ->with(['league', 'home', 'away'])
            ->whereIn('status', config('football.open_statuses'))
            ->where('starts_at', '>', now())
            ->orderBy('starts_at')
            ->limit($limit)
            ->get();

        return $fixtures->map(function (SportFixture $fixture) use ($zone): array {
            return [
                'home' => sport_name($fixture->home),
                'away' => sport_name($fixture->away),
                'league' => sport_name($fixture->league),
                'badge' => '',
                'clock' => $this->whenLabel($fixture->starts_at, $zone),
                'score' => '–',
            ];
        })->all();
    }

    private function whenLabel(Carbon $startsAt, string $zone): string
    {
        $local = $startsAt->copy()->timezone($zone);
        $today = now()->timezone($zone)->startOfDay();
        $day = $local->copy()->startOfDay();
        $clock = sport_digits($local->format('H:i'));

        if ($day->equalTo($today)) {
            return __('sport.today').' '.$clock;
        }

        if ($day->equalTo($today->copy()->addDay())) {
            return __('sport.tomorrow').' '.$clock;
        }

        return sport_digits($local->format('d.m')).' '.$clock;
    }

    private function zone(?User $user): string
    {
        $zone = $user?->timezone;
        if (is_string($zone) && $zone !== '') {
            try {
                new \DateTimeZone($zone);

                return $zone;
            } catch (\Throwable) {
                // Bayi saati geçersizse gösterim dilimine düş.
            }
        }

        return display_timezone();
    }

    private function limit(): int
    {
        return max(1, (int) config('home.matches.limit', 5));
    }
}

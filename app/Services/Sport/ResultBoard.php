<?php

namespace App\Services\Sport;

use App\Models\SportFixture;
use App\Models\SportLeague;
use App\Models\SportTeam;
use App\Models\SportTranslation;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/** Sonuçlar sayfası: son 3 gün, takım ve lig filtresi. Liste ve lig seçenekleri 10 dk önbellekte. */
class ResultBoard
{
    public const DAYS = 3;

    /**
     * @return array{days: Collection, leagues: list<array{id: int, label: string}>, team: string, leagueId: ?int, date: ?string, dates: list<array{value: string, label: string}>, filtered: bool}
     */
    public function present(Request $request): array
    {
        $date = $this->date($request->query('tarih'));
        $leagueId = $this->leagueId($request->query('lig'));
        $team = trim((string) $request->query('takim', ''));
        $teamKey = \sport_search_key($team);
        $teamActive = mb_strlen($teamKey) >= 2;
        [$from, $until] = $this->span($date);

        $leagues = Cache::remember($this->cacheKey('leagues', [$date, app()->getLocale()]), 600, function () use ($from, $until) {
            return $this->leagues($from, $until);
        });

        $ids = Cache::remember($this->cacheKey('rows', [$date, $leagueId, $teamActive ? $teamKey : '']), 600, function () use ($from, $until, $leagueId, $teamActive, $teamKey) {
            return $this->fixtures($from, $until, $leagueId, $teamActive ? $teamKey : null)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        });
        $fixtures = $this->load(is_array($ids) ? $ids : []);

        return [
            'days' => $fixtures
                ->groupBy(fn (SportFixture $fixture) => display_instant($fixture->starts_at)->toDateString())
                ->map(fn ($group) => $group->groupBy('league_id')),
            'leagues' => is_array($leagues) ? $leagues : [],
            'team' => $team,
            'leagueId' => $leagueId,
            'date' => $date,
            'dates' => $this->dates(),
            'filtered' => $teamActive || $leagueId !== null || $date !== null,
        ];
    }

    public function forget(): void
    {
        Cache::forever('sport:results:v', (int) Cache::get('sport:results:v', 1) + 1);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function span(?string $date): array
    {
        if ($date === null) {
            return display_span_utc(-self::DAYS, 0);
        }

        $start = Carbon::parse($date, display_timezone())->startOfDay();

        return [$start->copy()->utc(), $start->copy()->endOfDay()->utc()];
    }

    private function date(mixed $raw): ?string
    {
        if (! is_string($raw) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1) {
            return null;
        }

        $day = Carbon::parse($raw, display_timezone())->startOfDay();
        $today = now()->timezone(display_timezone())->startOfDay();
        if ($day->lt($today->copy()->subDays(self::DAYS)) || $day->gt($today)) {
            return null;
        }

        return $day->toDateString();
    }

    private function leagueId(mixed $raw): ?int
    {
        if (! is_scalar($raw) || ! ctype_digit((string) $raw)) {
            return null;
        }

        $id = (int) $raw;

        return $id > 0 && SportLeague::query()->whereKey($id)->exists() ? $id : null;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function dates(): array
    {
        $today = now()->timezone(display_timezone())->startOfDay();
        $dates = [];
        for ($back = 0; $back <= self::DAYS; $back++) {
            $day = $today->copy()->subDays($back);
            $dates[] = [
                'value' => $day->toDateString(),
                'label' => sport_date($day, 'j F Y'),
            ];
        }

        return $dates;
    }

    /**
     * @return list<array{id: int, label: string}>
     */
    private function leagues(Carbon $from, Carbon $until): array
    {
        return SportLeague::query()
            ->with('country')
            ->whereHas('fixtures', fn ($query) => $query->finished()->whereBetween('starts_at', [$from, $until]))
            ->get()
            ->map(function (SportLeague $league) {
                $country = sport_name($league->country);
                $name = sport_name($league);

                return [
                    'id' => (int) $league->id,
                    'label' => $country !== '' ? $country.' - '.$name : $name,
                ];
            })
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    private function fixtures(Carbon $from, Carbon $until, ?int $leagueId, ?string $teamKey): Collection
    {
        $query = SportFixture::query()
            ->finished()
            ->whereBetween('starts_at', [$from, $until])
            ->orderByDesc('starts_at');

        if ($leagueId !== null) {
            $query->where('league_id', $leagueId);
        }

        if ($teamKey !== null) {
            $ids = $this->teamIds($teamKey);
            if ($ids === []) {
                return collect();
            }
            $query->where(fn ($inner) => $inner->whereIn('home_team_id', $ids)->orWhereIn('away_team_id', $ids));
        }

        return $query->get();
    }

    /**
     * @param  list<int>  $ids
     */
    private function load(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        $models = SportFixture::query()
            ->with(['league.country', 'home', 'away'])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        return collect($ids)->map(fn ($id) => $models->get($id))->filter()->values();
    }

    /**
     * @return list<int>
     */
    private function teamIds(string $key): array
    {
        $like = '%'.addcslashes($key, '%_\\').'%';

        return SportTeam::query()->where('name_key', 'like', $like)->pluck('id')
            ->merge(SportTranslation::query()->where('entity_type', 'team')->where('name_key', 'like', $like)->pluck('entity_id'))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<mixed>  $bits
     */
    private function cacheKey(string $part, array $bits): string
    {
        $version = (int) Cache::get('sport:results:v', 1);

        return 'sport:results:'.$version.':'.$part.':'.md5((string) json_encode($bits));
    }
}

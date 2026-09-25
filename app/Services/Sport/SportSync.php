<?php

namespace App\Services\Sport;

use App\Jobs\TranslateSportNames;
use App\Models\SportCountry;
use App\Models\SportFixture;
use App\Models\SportLeague;
use App\Models\SportMarket;
use App\Models\SportOdd;
use App\Models\SportSyncState;
use App\Models\SportTeam;
use Carbon\Carbon;

class SportSync
{
    public function __construct(
        private readonly ApiFootballClient $api,
        private readonly OddsMapper $mapper,
        private readonly MarginEngine $margins,
    ) {}

    public function leagues(): int
    {
        return $this->run('leagues', function (): int {
            $count = 0;
            foreach (config('football.default_leagues') as $index => $apiId) {
                $body = $this->api->get('/leagues', ['id' => $apiId, 'current' => 'true']);
                $row = $body['response'][0] ?? null;
                if (! is_array($row)) {
                    continue;
                }
                $country = SportCountry::query()->updateOrCreate(
                    ['name' => $row['country']['name']],
                    ['code' => $row['country']['code'] ?? null, 'flag' => $row['country']['flag'] ?? null],
                );
                $season = collect($row['seasons'] ?? [])->firstWhere('current', true)['year'] ?? null;
                $league = SportLeague::query()->firstOrNew(['api_id' => $row['league']['id']]);
                $league->fill([
                    'country_id' => $country->id,
                    'name' => $row['league']['name'],
                    'logo' => $row['league']['logo'] ?? null,
                    'season' => $season,
                ]);
                if (! $league->exists) {
                    $league->is_active = true;
                    $league->is_featured = true;
                    $league->sort_order = $index;
                }
                $league->save();
                $count++;
            }

            TranslateSportNames::dispatch();

            return $count;
        });
    }

    public function fixtures(): int
    {
        return $this->run('fixtures', function (): int {
            $count = 0;
            $from = now()->utc()->toDateString();
            $to = now()->utc()->addDays(3)->toDateString();
            $leagues = SportLeague::query()->where('is_active', true)->get();

            foreach ($leagues as $league) {
                $body = $this->api->get('/fixtures', [
                    'league' => $league->api_id,
                    'season' => $league->season,
                    'from' => $from,
                    'to' => $to,
                ]);
                foreach ($body['response'] ?? [] as $row) {
                    $this->storeFixture($league, $row);
                    $count++;
                }
            }

            TranslateSportNames::dispatch();

            return $count;
        });
    }

    public function odds(bool $soon): int
    {
        return $this->run($soon ? 'odds_soon' : 'odds', function () use ($soon): int {
            $count = 0;
            $dates = $soon
                ? [now()->utc()->toDateString()]
                : [now()->utc()->toDateString(), now()->utc()->addDay()->toDateString(), now()->utc()->addDays(2)->toDateString()];

            foreach ($dates as $date) {
                $page = 1;
                $total = 1;
                while ($page <= $total) {
                    $body = $this->api->get('/odds', [
                        'date' => $date,
                        'bookmaker' => config('football.bookmaker'),
                        'page' => $page,
                    ]);
                    if ($body === null) {
                        break;
                    }
                    $total = (int) ($body['paging']['total'] ?? 1);
                    foreach ($body['response'] ?? [] as $row) {
                        $count += $this->storeOdds($row, $soon);
                    }
                    $page++;
                }
            }

            return $count;
        });
    }

    public function results(): int
    {
        return $this->run('results', function (): int {
            $ids = SportFixture::query()
                ->where('starts_at', '<=', now())
                ->whereNotIn('status', ['FT', 'AET', 'PEN'])
                ->limit(20)
                ->pluck('api_id');
            if ($ids->isEmpty()) {
                return 0;
            }
            $body = $this->api->get('/fixtures', ['ids' => $ids->implode('-')], true);
            $count = 0;
            foreach ($body['response'] ?? [] as $row) {
                $fixture = SportFixture::query()->where('api_id', $row['fixture']['id'])->first();
                if ($fixture === null) {
                    continue;
                }
                $fixture->status = (string) $row['fixture']['status']['short'];
                $fixture->score_home = $row['goals']['home'];
                $fixture->score_away = $row['goals']['away'];
                $fixture->ht_home = $row['score']['halftime']['home'] ?? null;
                $fixture->ht_away = $row['score']['halftime']['away'] ?? null;
                $fixture->save();
                $count++;
            }
            $this->suspendStarted();

            return $count;
        }, true);
    }

    public function suspendStarted(): int
    {
        $ids = SportFixture::query()
            ->where(function ($query): void {
                $query->where('starts_at', '<=', now())->orWhereNotIn('status', config('football.open_statuses'));
            })
            ->pluck('id');

        return SportOdd::query()->whereIn('fixture_id', $ids)->where('suspended', false)->update(['suspended' => true]);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function storeFixture(SportLeague $league, array $row): SportFixture
    {
        $home = $this->team($row['teams']['home']);
        $away = $this->team($row['teams']['away']);
        $fixture = SportFixture::query()->firstOrNew(['api_id' => $row['fixture']['id']]);
        if (! $fixture->exists) {
            $max = (int) SportFixture::query()->max('bulletin_code');
            $fixture->bulletin_code = $max === 0 ? 1001 : $max + 1;
        }
        $fixture->fill([
            'league_id' => $league->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'starts_at' => Carbon::parse($row['fixture']['date'])->utc(),
            'status' => (string) $row['fixture']['status']['short'],
            'score_home' => $row['goals']['home'] ?? null,
            'score_away' => $row['goals']['away'] ?? null,
            'ht_home' => $row['score']['halftime']['home'] ?? null,
            'ht_away' => $row['score']['halftime']['away'] ?? null,
        ]);
        $fixture->save();

        return $fixture;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function storeOdds(array $row, bool $soon): int
    {
        $fixture = SportFixture::query()->where('api_id', $row['fixture']['id'] ?? null)->first();
        if ($fixture === null || ! $fixture->league->is_active) {
            return 0;
        }
        if ($soon && $fixture->starts_at->gt(now()->addHours(2))) {
            return 0;
        }
        if (! $fixture->isOpen()) {
            $this->suspendStarted();

            return 0;
        }

        $count = 0;
        $markets = SportMarket::query()->get()->keyBy('code');
        $bookmaker = collect($row['bookmakers'] ?? [])->firstWhere('id', (int) config('football.bookmaker'));
        foreach ($bookmaker['bets'] ?? [] as $bet) {
            foreach ($this->mapper->map($bet) as $mapped) {
                $market = $markets[$mapped['market']] ?? null;
                if ($market === null) {
                    continue;
                }
                $shown = $this->margins->show($mapped['raw'], $fixture, $market->code, null);
                $existing = SportOdd::query()->where([
                    'fixture_id' => $fixture->id,
                    'market_id' => $market->id,
                    'outcome' => $mapped['outcome'],
                ])->first();
                $direction = null;
                if ($existing !== null && bccomp((string) $existing->shown_odd, $shown, 2) !== 0) {
                    $direction = bccomp($shown, (string) $existing->shown_odd, 2) === 1 ? 'up' : 'down';
                }
                SportOdd::query()->updateOrCreate(
                    ['fixture_id' => $fixture->id, 'market_id' => $market->id, 'outcome' => $mapped['outcome']],
                    [
                        'raw_odd' => $mapped['raw'],
                        'shown_odd' => $shown,
                        'direction' => $direction ?? $existing?->direction,
                        'suspended' => false,
                        'quoted_at' => isset($row['update']) ? Carbon::parse($row['update'])->utc() : now(),
                    ],
                );
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $team
     */
    private function team(array $team): SportTeam
    {
        return SportTeam::query()->updateOrCreate(
            ['api_id' => $team['id']],
            ['name' => $team['name'], 'logo' => $team['logo'] ?? null],
        );
    }

    private function run(string $code, callable $work, bool $critical = false): int
    {
        try {
            $count = $work();
            SportSyncState::query()->updateOrCreate(['code' => $code], ['last_synced_at' => now(), 'last_error' => null]);

            return (int) $count;
        } catch (\Throwable $exception) {
            SportSyncState::query()->updateOrCreate(['code' => $code], ['last_error' => $exception->getMessage()]);
            if ($critical) {
                throw $exception;
            }

            return 0;
        }
    }
}

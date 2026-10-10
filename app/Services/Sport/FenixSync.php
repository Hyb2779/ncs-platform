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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Fenix beslemesi: maç önü ve canlı, bütün sporlar ve bütün marketler. */
class FenixSync
{
    public function __construct(private readonly MarginEngine $margins) {}

    /** @return array{events: int, fixtures: int, odds: int, errors: int} */
    public function prematch(): array
    {
        return $this->pull('prematch', (string) config('fenix.prematch_url'), false);
    }

    /** @return array{events: int, fixtures: int, odds: int, errors: int} */
    public function live(): array
    {
        return $this->pull('live', (string) config('fenix.live_url'), true);
    }

    /** @return array{events: int, fixtures: int, odds: int, errors: int} */
    private function pull(string $code, string $url, bool $live): array
    {
        $stats = ['events' => 0, 'fixtures' => 0, 'odds' => 0, 'errors' => 0];

        try {
            $payload = Http::timeout($live ? 30 : 90)->acceptJson()->get($url)->throw()->json();
            $typeMap = $this->typeMap($payload['types'] ?? []);
            $markets = SportMarket::query()->get()->keyBy('code');
            $seen = [];

            foreach ($payload['events'] ?? [] as $event) {
                if (! is_array($event) || ! in_array($event['sport'] ?? '', ['football', 'basketball', 'tennis', 'volleyball'], true)) {
                    continue;
                }
                if ($live !== (bool) ($event['live'] ?? false)) {
                    continue;
                }
                $time = (int) ($event['match_time'] ?? 0);
                if (! $live && ($time <= now()->timestamp)) {
                    continue;
                }
                $stats['events']++;
                try {
                    $fixture = $this->storeFixture($event);
                    if ($live) {
                        $this->applyLive($fixture, $event);
                    }
                    $seen[] = (int) $event['eventid'];
                    $stats['fixtures']++;
                    $stats['odds'] += $this->storeOdds($fixture, $event['odds'] ?? [], $typeMap, $markets);
                } catch (\Throwable $exception) {
                    $stats['errors']++;
                    Log::warning('fenix_event_failed', ['eventid' => $event['eventid'] ?? null, 'error' => $exception->getMessage()]);
                }
            }

            if ($live) {
                $this->clearStaleLive($seen);
            }

            SportSyncState::query()->updateOrCreate(['code' => 'fenix-'.$code], ['last_synced_at' => now(), 'last_error' => null]);
            if (! $live) {
                TranslateSportNames::dispatch();
            }
        } catch (\Throwable $exception) {
            SportSyncState::query()->updateOrCreate(['code' => 'fenix-'.$code], ['last_error' => $exception->getMessage()]);
            throw $exception;
        }

        return $stats;
    }

    /**
     * @param  list<int>  $seen
     */
    private function clearStaleLive(array $seen): void
    {
        $query = SportFixture::query()
            ->whereIn('status', [...config('sport.live_statuses'), 'HT'])
            ->where(function ($inner): void {
                $inner->whereNull('score_source')->orWhere('score_source', '!=', 'manual');
            });
        if ($seen !== []) {
            $query->whereNotIn('api_id', $seen);
        }
        $query->update(['status' => 'NS', 'elapsed' => null]);
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string}> */
    public function typeMap(array $types): array
    {
        $wanted = (array) config('fenix.markets');
        $map = [];
        foreach ($types as $id => $type) {
            if (! is_array($type)) {
                continue;
            }
            $handicap = rtrim(rtrim(number_format((float) ($type['handicap'] ?? 0), 2, '.', ''), '0'), '.');
            $handicap = $handicap === '' ? '0' : $handicap;
            $group = (string) ($type['market_name'] ?? '');
            $selection = (string) ($type['selection_name'] ?? '');
            $key = $group.'|'.$selection.'|'.$handicap;
            $known = $wanted[$key] ?? null;
            $map[(string) $id] = [
                $known[0] ?? 'BOOK',
                $known[1] ?? (string) $id,
                $group,
                $selection,
                $handicap,
            ];
        }

        return $map;
    }

    private function storeFixture(array $event): SportFixture
    {
        $country = SportCountry::query()->updateOrCreate(['name' => (string) ($event['country_name'] ?? 'Dünya')], []);

        $league = SportLeague::query()->firstOrNew(['api_id' => (int) $event['competition_id']]);
        $league->fill(['country_id' => $country->id, 'name' => (string) $event['competition_name']]);
        if (! $league->exists) {
            $league->is_active = true;
            $league->is_featured = false;
            $league->sort_order = 1000;
        }
        $league->save();

        $home = SportTeam::query()->updateOrCreate(['api_id' => (int) $event['home_id']], ['name' => (string) $event['home_name']]);
        $away = SportTeam::query()->updateOrCreate(['api_id' => (int) $event['away_id']], ['name' => (string) $event['away_name']]);

        $fixture = SportFixture::query()->firstOrNew(['api_id' => (int) $event['eventid']]);
        if (! $fixture->exists) {
            $max = (int) SportFixture::query()->max('bulletin_code');
            $fixture->bulletin_code = $max === 0 ? 1001 : $max + 1;
            $fixture->status = 'NS';
        }
        $fixture->forceFill([
            'league_id' => $league->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'starts_at' => Carbon::createFromTimestampUTC((int) $event['match_time']),
            'mbs' => max(1, (int) ($event['mbs'] ?? 1)),
            'betradar_id' => isset($event['betradar_id']) ? (int) $event['betradar_id'] : null,
            'sport' => (string) $event['sport'],
        ])->save();

        return $fixture;
    }

    private function applyLive(SportFixture $fixture, array $event): void
    {
        if ($fixture->score_source === 'manual') {
            return;
        }

        $meta = is_array($event['live_metadata'] ?? null) ? $event['live_metadata'] : [];
        $period = strtoupper((string) ($meta['periodStatus'] ?? ''));
        $status = match ($period) {
            '1H' => '1H',
            'HT', 'HALFTIME' => 'HT',
            '2H' => '2H',
            'ET', 'OT' => 'ET',
            'P', 'PEN' => 'P',
            default => 'LIVE',
        };
        $minute = isset($meta['minutes']) && is_numeric($meta['minutes']) ? (int) $meta['minutes'] : null;
        $board = array_filter([
            'minute' => $minute,
            'period' => $period !== '' ? $period : null,
            'stoppage' => $meta['stoppageTime'] ?? null,
            'home_corners' => $meta['homeTeamCorners'] ?? null,
            'away_corners' => $meta['awayTeamCorners'] ?? null,
            'home_yellow' => $meta['homeTeamYellowCards'] ?? null,
            'away_yellow' => $meta['awayTeamYellowCards'] ?? null,
            'home_red' => $meta['homeTeamRedCards'] ?? null,
            'away_red' => $meta['awayTeamRedCards'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        $fill = [
            'status' => $status,
            'elapsed' => $minute,
            'live_meta' => $board,
        ];
        if (isset($meta['homeTeamScore'], $meta['awayTeamScore']) && is_numeric($meta['homeTeamScore']) && is_numeric($meta['awayTeamScore'])) {
            $fill['score_home'] = (string) (int) $meta['homeTeamScore'];
            $fill['score_away'] = (string) (int) $meta['awayTeamScore'];
        }
        foreach ((array) ($meta['periods'] ?? []) as $row) {
            if ((int) ($row['num'] ?? 0) === 1 && isset($row['homeTeamScore'], $row['awayTeamScore'])) {
                $fill['ht_home'] = (string) (int) $row['homeTeamScore'];
                $fill['ht_away'] = (string) (int) $row['awayTeamScore'];
            }
        }
        $fixture->forceFill($fill)->save();
    }

    private function storeOdds(SportFixture $fixture, array $odds, array $typeMap, $markets): int
    {
        $existing = SportOdd::query()->where('fixture_id', $fixture->id)->get();
        $byType = [];
        $bySlot = [];
        foreach ($existing as $row) {
            if ($row->type_id !== null) {
                $byType[(string) $row->type_id] = $row;
            }
            $bySlot[$row->market_id.'|'.$row->outcome] = $row;
        }

        $now = now();
        $rows = [];
        $seen = [];
        $groups = [];
        foreach ($odds as $typeId => $row) {
            $target = $typeMap[(string) $typeId] ?? null;
            $market = $target ? ($markets[$target[0]] ?? null) : null;
            $raw = is_array($row) && is_numeric($row['odds'] ?? null) ? (float) $row['odds'] : null;
            if ($market === null || $raw === null || $raw < 1.01) {
                continue;
            }
            $raw = number_format($raw, 2, '.', '');
            $slot = $market->id.'|'.$target[1];
            $current = $byType[(string) $typeId] ?? $bySlot[$slot] ?? null;
            if ($current !== null && $current->type_id === null) {
                $current->type_id = (int) $typeId;
                $current->save();
                $byType[(string) $typeId] = $current;
            }
            $shown = $this->margins->show($raw, $fixture, $market->code, null);
            $direction = $current?->direction;
            if ($current !== null && bccomp((string) $current->shown_odd, $shown, 2) !== 0) {
                $direction = bccomp($shown, (string) $current->shown_odd, 2) === 1 ? 'up' : 'down';
            }
            $rows[] = [
                'fixture_id' => $fixture->id,
                'market_id' => $market->id,
                'outcome' => $target[1],
                'type_id' => (int) $typeId,
                'group_name' => $target[2],
                'selection_name' => $target[3],
                'handicap' => $target[4] === '' ? '0' : $target[4],
                'raw_odd' => $raw,
                'shown_odd' => $shown,
                'direction' => $direction,
                'suspended' => false,
                'quoted_at' => $now->toDateTimeString(),
                'market_uid' => (string) ($row['market_uid'] ?? ''),
                'created_at' => $current?->created_at?->toDateTimeString() ?? $now->toDateTimeString(),
                'updated_at' => $now->toDateTimeString(),
            ];
            $seen[] = (int) $typeId;
            if ($target[2] !== '') {
                $groups[$target[2]] = true;
            }
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            if ($chunk === []) {
                continue;
            }
            SportOdd::query()->upsert($chunk, ['fixture_id', 'type_id'], [
                'market_id', 'outcome', 'group_name', 'selection_name', 'handicap',
                'raw_odd', 'shown_odd', 'direction', 'suspended', 'quoted_at', 'market_uid', 'updated_at',
            ]);
        }

        $stale = SportOdd::query()->where('fixture_id', $fixture->id);
        if ($seen !== []) {
            $stale->where(function ($query) use ($seen): void {
                $query->whereNull('type_id')->orWhereNotIn('type_id', $seen);
            });
        }
        $stale->update(['suspended' => true]);

        $fixture->forceFill(['offer_count' => count($groups)])->save();

        return count($rows);
    }
}

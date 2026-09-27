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

/** Fenix beslemesinden bülten (maç önü). Tek spor kaynağı. */
class FenixSync
{
    public function __construct(private readonly MarginEngine $margins) {}

    /** @return array{events: int, fixtures: int, odds: int, errors: int} */
    public function prematch(): array
    {
        $stats = ['events' => 0, 'fixtures' => 0, 'odds' => 0, 'errors' => 0];

        try {
            $payload = Http::timeout(90)->acceptJson()->get((string) config('fenix.prematch_url'))->throw()->json();
            $typeMap = $this->typeMap($payload['types'] ?? []);
            $markets = SportMarket::query()->get()->keyBy('code');
            $until = now()->addDays((int) config('fenix.horizon_days', 4))->timestamp;

            foreach ($payload['events'] ?? [] as $event) {
                if (! is_array($event) || ($event['sport'] ?? '') !== 'football' || ! empty($event['live'])) {
                    continue;
                }
                $time = (int) ($event['match_time'] ?? 0);
                if ($time <= now()->timestamp || $time > $until) {
                    continue;
                }
                $stats['events']++;
                try {
                    $fixture = $this->storeFixture($event);
                    $stats['fixtures']++;
                    $stats['odds'] += $this->storeOdds($fixture, $event['odds'] ?? [], $typeMap, $markets);
                } catch (\Throwable $exception) {
                    $stats['errors']++;
                    Log::warning('fenix_event_failed', ['eventid' => $event['eventid'] ?? null, 'error' => $exception->getMessage()]);
                }
            }

            SportSyncState::query()->updateOrCreate(['code' => 'fenix-prematch'], ['last_synced_at' => now(), 'last_error' => null]);
            TranslateSportNames::dispatch();
        } catch (\Throwable $exception) {
            SportSyncState::query()->updateOrCreate(['code' => 'fenix-prematch'], ['last_error' => $exception->getMessage()]);
            throw $exception;
        }

        return $stats;
    }

    /** @return array<string, array{0: string, 1: string}> Fenix tip no => [market kodu, seçenek] */
    public function typeMap(array $types): array
    {
        $wanted = (array) config('fenix.markets');
        $map = [];
        foreach ($types as $id => $type) {
            if ((int) ($type['sport_id'] ?? 0) !== 1) {
                continue;
            }
            $handicap = rtrim(rtrim(number_format((float) ($type['handicap'] ?? 0), 2, '.', ''), '0'), '.');
            $key = ($type['market_name'] ?? '').'|'.($type['selection_name'] ?? '').'|'.($handicap === '' ? '0' : $handicap);
            if (isset($wanted[$key])) {
                $map[(string) $id] = $wanted[$key];
            }
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
        ])->save();

        return $fixture;
    }

    private function storeOdds(SportFixture $fixture, array $odds, array $typeMap, $markets): int
    {
        $seen = [];
        $count = 0;
        foreach ($odds as $typeId => $row) {
            $target = $typeMap[(string) $typeId] ?? null;
            $market = $target ? ($markets[$target[0]] ?? null) : null;
            $raw = is_array($row) && is_numeric($row['odds'] ?? null) ? (float) $row['odds'] : null;
            if ($market === null || $raw === null || $raw < 1.01) {
                continue;
            }
            $raw = number_format($raw, 2, '.', '');
            $shown = $this->margins->show($raw, $fixture, $market->code, null);
            $existing = SportOdd::query()->where(['fixture_id' => $fixture->id, 'market_id' => $market->id, 'outcome' => $target[1]])->first();
            $direction = null;
            if ($existing !== null && bccomp((string) $existing->shown_odd, $shown, 2) !== 0) {
                $direction = bccomp($shown, (string) $existing->shown_odd, 2) === 1 ? 'up' : 'down';
            }
            $odd = SportOdd::query()->updateOrCreate(
                ['fixture_id' => $fixture->id, 'market_id' => $market->id, 'outcome' => $target[1]],
                [
                    'raw_odd' => $raw,
                    'shown_odd' => $shown,
                    'direction' => $direction ?? $existing?->direction,
                    'suspended' => false,
                    'quoted_at' => now(),
                    'market_uid' => (string) ($row['market_uid'] ?? ''),
                ],
            );
            $seen[] = $odd->id;
            $count++;
        }

        SportOdd::query()->where('fixture_id', $fixture->id)->whereNotIn('id', $seen ?: [0])->update(['suspended' => true]);

        return $count;
    }
}

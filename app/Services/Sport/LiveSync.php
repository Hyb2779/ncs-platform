<?php

namespace App\Services\Sport;

use App\Jobs\SettleFinishedFixtures;
use App\Models\SportFixture;
use App\Models\SportSyncState;
use Illuminate\Support\Collection;

class LiveSync
{
    public function __construct(
        private readonly ApiFootballClient $api,
        private readonly SportSync $sync,
    ) {}

    public function run(): int
    {
        $watched = SportFixture::query()
            ->where('score_source', '!=', 'manual')
            ->whereHas('selections', function ($query): void {
                $query->where('status', 'pending')
                    ->where('kickoff_at', '<=', now())
                    ->whereHas('coupon', fn ($coupon) => $coupon->where('status', 'pending'));
            })
            ->get();

        if ($watched->isEmpty()) {
            return 0;
        }

        $body = $this->api->get('/fixtures', ['live' => 'all'], false, 'live-sync');
        SportSyncState::query()->updateOrCreate(
            ['code' => 'live-sync'],
            ['last_synced_at' => now(), 'last_error' => $body === null ? 'empty' : null],
        );
        if ($body === null) {
            return 0;
        }

        $liveIds = [];
        $count = 0;
        foreach ($body['response'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $apiId = $row['fixture']['id'] ?? null;
            if (is_numeric($apiId)) {
                $liveIds[] = (int) $apiId;
            }
            $fixture = SportFixture::query()->where('api_id', $apiId)->first();
            if ($fixture === null || $fixture->isManual()) {
                continue;
            }
            $this->applyLive($fixture, $row);
            $count++;
        }

        $dropped = $watched->filter(function (SportFixture $fixture) use ($liveIds): bool {
            return $this->wasLive($fixture->status) && ! in_array((int) $fixture->api_id, $liveIds, true);
        })->values();

        $finished = $this->fetchDropped($dropped);
        if ($finished !== []) {
            SettleFinishedFixtures::dispatchSync($finished);
        }

        return $count;
    }

    /**
     * @param  Collection<int, SportFixture>  $dropped
     * @return list<int>
     */
    private function fetchDropped(Collection $dropped): array
    {
        $apiIds = $dropped->pluck('api_id')->filter()->unique()->take((int) config('sport.batch_size'))->values();
        if ($apiIds->isEmpty()) {
            return [];
        }

        $body = $this->api->get('/fixtures', ['ids' => $apiIds->implode('-')], false, 'live-sync');
        if ($body === null) {
            return [];
        }

        $finished = [];
        foreach ($body['response'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $fixture = SportFixture::query()->where('api_id', $row['fixture']['id'] ?? null)->first();
            if ($fixture === null || $fixture->isManual()) {
                continue;
            }
            $this->sync->applyScoreRow($fixture, $row);
            if (in_array($fixture->status, config('sport.settle_statuses'), true) && $fixture->ft_home !== null && $fixture->ft_away !== null) {
                $fixture->settled_at = now();
                $finished[] = $fixture->id;
            }
            $fixture->save();
        }

        return $finished;
    }

    private function wasLive(string $status): bool
    {
        return in_array($status, [...config('sport.live_statuses'), 'HT'], true);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function applyLive(SportFixture $fixture, array $row): void
    {
        $fixture->status = (string) ($row['fixture']['status']['short'] ?? $fixture->status);
        $elapsed = $row['fixture']['status']['elapsed'] ?? null;
        $fixture->elapsed = is_numeric($elapsed) ? (int) $elapsed : null;
        $fixture->score_home = $row['goals']['home'] ?? $fixture->score_home;
        $fixture->score_away = $row['goals']['away'] ?? $fixture->score_away;
        if (is_numeric($row['score']['halftime']['home'] ?? null)) {
            $fixture->ht_home = (string) $row['score']['halftime']['home'];
        }
        if (is_numeric($row['score']['halftime']['away'] ?? null)) {
            $fixture->ht_away = (string) $row['score']['halftime']['away'];
        }
        $fixture->save();
    }
}

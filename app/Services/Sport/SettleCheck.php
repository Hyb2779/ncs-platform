<?php

namespace App\Services\Sport;

use App\Models\Coupon;
use App\Models\CouponSelection;
use App\Models\SportFixture;
use App\Models\SportSyncState;
use App\Models\SportWarning;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class SettleCheck
{
    public function __construct(
        private readonly ApiFootballClient $api,
        private readonly SportSync $sync,
        private readonly CouponSettler $settler,
    ) {}

    public function run(): int
    {
        try {
            $due = $this->dueSelections();
            $this->fetchScores($due);
            $due = $this->dueSelections();
            $this->applyLocalRules($due);

            $couponIds = $due->pluck('coupon_id')->unique()->filter();
            foreach ($couponIds as $couponId) {
                $coupon = Coupon::query()->find($couponId);
                if ($coupon !== null) {
                    $this->settler->settle($coupon);
                }
            }

            SportSyncState::query()->updateOrCreate(
                ['code' => 'settle-check'],
                ['last_synced_at' => now(), 'last_error' => null],
            );

            return $couponIds->count();
        } catch (\Throwable $exception) {
            SportSyncState::query()->updateOrCreate(
                ['code' => 'settle-check'],
                ['last_error' => $exception->getMessage()],
            );

            throw $exception;
        }
    }

    /**
     * @return Collection<int, CouponSelection>
     */
    public function dueSelections(): Collection
    {
        $cutoff = now()->subMinutes((int) config('sport.settle_after_minutes'));

        return CouponSelection::query()
            ->where('status', 'pending')
            ->where('kickoff_at', '<=', $cutoff)
            ->whereHas('coupon', fn ($query) => $query->where('status', 'pending'))
            ->with(['fixture', 'coupon'])
            ->get();
    }

    /**
     * @param  Collection<int, CouponSelection>  $due
     */
    private function fetchScores(Collection $due): void
    {
        $ids = $due->map(fn (CouponSelection $selection) => $selection->fixture)
            ->filter()
            ->unique('id')
            ->filter(function (SportFixture $fixture): bool {
                if ($fixture->isManual() || $fixture->settled_at !== null) {
                    return false;
                }

                return ! (
                    in_array($fixture->status, config('sport.settle_statuses'), true)
                    && $fixture->ft_home !== null
                    && $fixture->ft_away !== null
                );
            })
            ->pluck('api_id')
            ->filter()
            ->values();

        foreach ($ids->chunk((int) config('sport.batch_size')) as $chunk) {
            $body = $this->api->get('/fixtures', ['ids' => $chunk->implode('-')], true) ?? [];
            foreach ($body['response'] ?? [] as $row) {
                $fixture = SportFixture::query()->where('api_id', $row['fixture']['id'] ?? null)->first();
                if ($fixture === null || $fixture->isManual()) {
                    continue;
                }
                $this->sync->applyScoreRow($fixture, $row);
                if (in_array($fixture->status, config('sport.settle_statuses'), true) && $fixture->ft_home !== null && $fixture->ft_away !== null) {
                    $fixture->settled_at = now();
                }
                $fixture->save();
            }
        }
    }

    /**
     * @param  Collection<int, CouponSelection>  $due
     */
    private function applyLocalRules(Collection $due): void
    {
        foreach ($due as $selection) {
            $fixture = $selection->fixture;
            if ($fixture === null || $selection->kickoff_at === null) {
                continue;
            }

            $kickoff = Carbon::parse($selection->kickoff_at);
            $voidAt = $kickoff->copy()->addHours((int) config('sport.void_after_hours'));
            $staleAt = $kickoff->copy()->addHours((int) config('sport.stale_after_hours'));
            $status = $fixture->status;

            if (in_array($status, config('sport.stale_statuses'), true) && now()->gte($staleAt)) {
                SportWarning::query()->updateOrCreate(
                    ['type' => SportWarning::Stale, 'fixture_id' => $fixture->id],
                    ['resolved_at' => null],
                );

                continue;
            }

            $voidable = in_array($status, [...config('sport.void_statuses'), ...config('sport.wait_statuses')], true);
            if ($voidable && now()->gte($voidAt)) {
                $selection->status = 'void';
                $selection->settled_at = now();
                $selection->save();
                if ($fixture->settled_at === null) {
                    $fixture->settled_at = now();
                    $fixture->save();
                }
            }
        }
    }
}

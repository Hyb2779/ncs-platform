<?php

namespace App\Services\Sport;

use App\Models\Coupon;
use App\Models\CouponSelection;
use App\Models\SportFixture;
use App\Models\User;

class CouponLimitGuard
{
    /** @var array<int, EffectiveSportLimit> */
    private array $cached = [];

    public function __construct(
        private readonly SportLimits $limits,
        private readonly CouponCalculator $calculator,
    ) {}

    /**
     * @param  list<array{fixture_id: int, outcome: string, market: string, shown: string, fixture: SportFixture}>  $rows
     */
    public function assertSlip(User $user, string $mode, string $stake, array $rows): void
    {
        $limit = $this->limits->forUser($user);
        $count = count($rows);
        $live = $this->anyLive($rows);
        $couponMode = $mode === 'single' ? 'single' : 'combo';

        $this->assertMin($stake, $limit->min_stake, 'sport.errors.min_stake', 'amount');
        $this->assertMax($stake, $limit->max_stake_general, 'sport.errors.max_stake_general', 'amount');
        if ($mode === 'single') {
            $this->assertMax($stake, $limit->max_stake_single, 'sport.errors.max_stake_single', 'amount');
        }
        if ($live) {
            $this->assertMax($stake, $limit->max_stake_live, 'sport.errors.max_stake_live', 'amount');
        }
        if ($mode === 'combo' && $count < 2) {
            throw new CouponException('sport.errors.combo_min', ['count' => 2]);
        }
        if ($mode === 'combo') {
            $this->assertMax((string) $count, $limit->max_selections, 'sport.errors.max_selections', 'count');
        }

        $groups = $mode === 'single' ? array_map(fn (array $row): array => [$row], $rows) : [$rows];
        foreach ($groups as $group) {
            foreach ($group as $row) {
                $this->assertSelectionOdds($row, $limit);
            }
            $prices = array_column($group, 'shown');
            $total = $this->calculator->total($couponMode, $prices);
            $this->assertMin($total, $limit->min_coupon_odds, 'sport.errors.min_coupon_odds', 'odd');
            $this->assertMax($total, $limit->max_coupon_odds, 'sport.errors.max_coupon_odds', 'odd');
            $win = $this->calculator->payout($couponMode, $stake, $prices);
            $this->assertMax($win, $limit->max_payout_general, 'sport.errors.max_payout_general', 'amount');
            if ($mode === 'single' && ! $live) {
                $this->assertMax($win, $limit->max_payout_single, 'sport.errors.max_payout_single', 'amount');
            }
            if ($live) {
                $this->assertMax($win, $limit->max_payout_live, 'sport.errors.max_payout_live', 'amount');
                if ($mode === 'single') {
                    $this->assertMax($win, $limit->max_payout_live_single, 'sport.errors.max_payout_live_single', 'amount');
                }
            }
        }
    }

    /**
     * @param  list<array{fixture_id: int, outcome: string, market: string, shown: string, fixture: SportFixture}>  $rows
     */
    public function assertTotals(User $user, string $mode, string $stake, array $rows): void
    {
        $limit = $this->limits->forUser($user);
        $today = Coupon::query()
            ->where('user_id', $user->id)
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->where('placed_at', '>=', now()->timezone($user->timezone)->startOfDay()->utc())
            ->sum('stake');
        $this->assertMax(bcadd((string) $today, $stake, 2), $limit->daily_max, 'sport.errors.daily_max', 'amount');

        foreach ($rows as $row) {
            $fixtureStake = (string) Coupon::query()
                ->where('user_id', $user->id)
                ->where('status', 'pending')
                ->whereHas('selections', fn ($query) => $query->where('fixture_id', $row['fixture_id']))
                ->sum('stake');
            $this->assertMax(bcadd($fixtureStake, $stake, 2), $limit->max_stake_per_fixture, 'sport.errors.max_stake_per_fixture', 'amount');

            $outcomeStake = (string) Coupon::query()
                ->where('user_id', $user->id)
                ->where('status', 'pending')
                ->whereHas('selections', function ($query) use ($row): void {
                    $query->where('fixture_id', $row['fixture_id'])
                        ->where('market_code', $row['market'])
                        ->where('outcome', $row['outcome']);
                })
                ->sum('stake');
            $this->assertMax(bcadd($outcomeStake, $stake, 2), $limit->max_stake_per_outcome, 'sport.errors.max_stake_per_outcome', 'amount');
        }

        $repeatField = $mode === 'single' ? 'repeat_limit_single' : 'repeat_limit_combo';
        $repeatKey = $mode === 'single' ? 'sport.errors.repeat_limit_single' : 'sport.errors.repeat_limit_combo';
        $open = Coupon::query()
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->where('type', $mode === 'single' ? 'single' : 'combo')
            ->with('selections')
            ->get();
        $wanted = $this->signature($rows);
        $repeatStake = '0.00';
        foreach ($open as $coupon) {
            if ($this->storedSignature($coupon->selections) === $wanted) {
                $repeatStake = bcadd($repeatStake, (string) $coupon->stake, 2);
            }
        }
        $this->assertMax(bcadd($repeatStake, $stake, 2), $limit->{$repeatField}, $repeatKey, 'amount');
    }

    public function allowsPrice(User $user, SportFixture $fixture, string $price): bool
    {
        $limit = $this->cached[$user->id] ??= $this->limits->forUser($user);
        $live = $this->fixtureLive($fixture);
        $min = $live ? $limit->min_odds_live : $limit->min_odds_prematch;
        $max = $live ? $limit->max_odds_live : $limit->max_odds_prematch;
        if ($min !== null && bccomp($price, (string) $min, 2) < 0) {
            return false;
        }
        if ($max !== null && bccomp($price, (string) $max, 2) > 0) {
            return false;
        }

        return true;
    }

    /**
     * @param  array{shown: string, fixture: SportFixture}  $row
     */
    private function assertSelectionOdds(array $row, EffectiveSportLimit $limit): void
    {
        $live = $this->fixtureLive($row['fixture']);
        $min = $live ? $limit->min_odds_live : $limit->min_odds_prematch;
        $max = $live ? $limit->max_odds_live : $limit->max_odds_prematch;
        $minKey = $live ? 'sport.errors.min_odds_live' : 'sport.errors.min_odds_prematch';
        $maxKey = $live ? 'sport.errors.max_odds_live' : 'sport.errors.max_odds_prematch';
        $this->assertMin($row['shown'], $min, $minKey, 'odd');
        $this->assertMax($row['shown'], $max, $maxKey, 'odd');
    }

    private function assertMin(string $value, mixed $floor, string $key, string $name): void
    {
        if ($floor === null) {
            return;
        }
        if (bccomp($value, (string) $floor, 2) < 0) {
            throw new CouponException($key, [$name => $floor]);
        }
    }

    private function assertMax(string $value, mixed $cap, string $key, string $name): void
    {
        if ($cap === null) {
            return;
        }
        $scale = $name === 'count' ? 0 : 2;
        if (bccomp($value, (string) $cap, $scale) > 0) {
            throw new CouponException($key, [$name => $cap]);
        }
    }

    /**
     * @param  list<array{fixture: SportFixture}>  $rows
     */
    private function anyLive(array $rows): bool
    {
        foreach ($rows as $row) {
            if ($this->fixtureLive($row['fixture'])) {
                return true;
            }
        }

        return false;
    }

    private function fixtureLive(SportFixture $fixture): bool
    {
        return in_array($fixture->status, [...config('sport.live_statuses'), 'HT'], true);
    }

    /**
     * @param  list<array{fixture_id: int, market: string, outcome: string}>  $rows
     */
    private function signature(array $rows): string
    {
        $parts = [];
        foreach ($rows as $row) {
            $parts[] = $row['fixture_id'].':'.$row['market'].':'.$row['outcome'];
        }
        sort($parts);

        return implode('|', $parts);
    }

    /**
     * @param  iterable<CouponSelection>  $selections
     */
    private function storedSignature(iterable $selections): string
    {
        $parts = [];
        foreach ($selections as $selection) {
            $parts[] = $selection->fixture_id.':'.$selection->market_code.':'.$selection->outcome;
        }
        sort($parts);

        return implode('|', $parts);
    }
}

<?php

namespace App\Jobs;

use App\Models\Coupon;
use App\Models\CouponSelection;
use App\Services\Sport\CouponSettler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class SettleFinishedFixtures implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<int>  $fixtureIds
     */
    public function __construct(public array $fixtureIds) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('sport:settle-finished'))->expireAfter(600)->releaseAfter(30)];
    }

    public function handle(CouponSettler $settler): void
    {
        if ($this->fixtureIds === []) {
            return;
        }

        $couponIds = CouponSelection::query()
            ->whereIn('fixture_id', $this->fixtureIds)
            ->where('status', 'pending')
            ->whereHas('coupon', fn ($query) => $query->where('status', 'pending'))
            ->distinct()
            ->pluck('coupon_id');

        foreach ($couponIds as $couponId) {
            $coupon = Coupon::query()->find($couponId);
            if ($coupon !== null) {
                $settler->settle($coupon);
            }
        }
    }
}

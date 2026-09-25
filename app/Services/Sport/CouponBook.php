<?php

namespace App\Services\Sport;

use App\Models\SportOdd;
use Illuminate\Support\Facades\Session;

class CouponBook
{
    /**
     * @return array{selections: list<array{odd_id: int, fixture_id: int, shown: string}>, stake: string, accept: bool, mode: string}
     */
    public function get(): array
    {
        return Session::get('sport.coupon', [
            'selections' => [],
            'stake' => '',
            'accept' => false,
            'mode' => 'combo',
        ]);
    }

    public function add(SportOdd $odd): void
    {
        $coupon = $this->get();
        $coupon['selections'] = array_values(array_filter(
            $coupon['selections'],
            fn (array $row) => (int) $row['fixture_id'] !== $odd->fixture_id,
        ));
        $coupon['selections'][] = [
            'odd_id' => $odd->id,
            'fixture_id' => $odd->fixture_id,
            'shown' => (string) $odd->shown_odd,
        ];
        Session::put('sport.coupon', $coupon);
    }

    public function update(string $stake, bool $accept, string $mode): void
    {
        $coupon = $this->get();
        $coupon['stake'] = $stake;
        $coupon['accept'] = $accept;
        $coupon['mode'] = $mode === 'single' ? 'single' : 'combo';
        Session::put('sport.coupon', $coupon);
    }

    public function clear(): void
    {
        Session::forget('sport.coupon');
    }
}

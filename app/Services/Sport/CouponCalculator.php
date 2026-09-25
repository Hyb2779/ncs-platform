<?php

namespace App\Services\Sport;

class CouponCalculator
{
    /**
     * @param  list<string|int|float>  $odds
     */
    public function total(string $mode, array $odds): string
    {
        if ($odds === []) {
            return '0.00';
        }

        if ($mode === 'single') {
            $sum = '0';
            foreach ($odds as $odd) {
                $sum = bcadd($sum, $this->normalize($odd), 8);
            }

            return $this->round2($sum);
        }

        $product = '1';
        foreach ($odds as $odd) {
            $product = bcmul($product, $this->normalize($odd), 8);
        }

        return $this->round2($product);
    }

    /**
     * @param  list<string|int|float>  $odds
     */
    public function payout(string $mode, string $stake, array $odds): string
    {
        if ($odds === [] || ! is_numeric($stake)) {
            return '0.00';
        }

        $amount = $this->normalize($stake);

        if ($mode === 'single') {
            $sum = '0';
            foreach ($odds as $odd) {
                $sum = bcadd($sum, $this->round2(bcmul($amount, $this->normalize($odd), 8)), 2);
            }

            return $sum;
        }

        return $this->round2(bcmul($amount, $this->total('combo', $odds), 8));
    }

    private function normalize(string|int|float $value): string
    {
        return bcadd((string) $value, '0', 8);
    }

    private function round2(string $value): string
    {
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '0');
        $fraction = str_pad(substr($fraction, 0, 8), 3, '0');
        $kept = substr($fraction, 0, 2);
        $rounded = $whole.'.'.$kept;

        if ((int) $fraction[2] >= 5) {
            $rounded = bcadd($rounded, '0.01', 2);
        }

        return ($negative ? '-' : '').$rounded;
    }
}

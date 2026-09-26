<?php

namespace App\Services\Sport;

class SportLimitCatalog
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            'TRY' => self::row('1.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '10000.00', '50000.00', '100000.00', '100000.00', '100000.00', '100000.00'),
            'USD' => self::row('1.00', '250.00', '250.00', '100.00', '500.00', '250.00', '250.00', '250.00', '1250.00', '2500.00', '2500.00', '1000.00', '1000.00'),
            'EUR' => self::row('1.00', '200.00', '200.00', '100.00', '400.00', '200.00', '200.00', '200.00', '1000.00', '2000.00', '2000.00', '800.00', '800.00'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function for(string $currency): array
    {
        return self::all()[$currency];
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(
        string $minStake,
        string $maxGeneral,
        string $maxSingle,
        string $maxLive,
        string $perFixture,
        string $perOutcome,
        string $repeatSingle,
        string $repeatCombo,
        string $daily,
        string $payoutGeneral,
        string $payoutSingle,
        string $payoutLive,
        string $payoutLiveSingle,
    ): array {
        return [
            'cash_out_enabled' => false,
            'min_stake' => $minStake,
            'max_stake_general' => $maxGeneral,
            'max_stake_single' => $maxSingle,
            'max_stake_live' => $maxLive,
            'max_stake_per_fixture' => $perFixture,
            'max_stake_per_outcome' => $perOutcome,
            'repeat_limit_single' => $repeatSingle,
            'repeat_limit_combo' => $repeatCombo,
            'daily_max' => $daily,
            'min_coupon_odds' => '1.01',
            'max_coupon_odds' => '500.00',
            'max_payout_general' => $payoutGeneral,
            'max_payout_single' => $payoutSingle,
            'max_payout_live' => $payoutLive,
            'max_payout_live_single' => $payoutLiveSingle,
            'min_odds_prematch' => '1.01',
            'max_odds_prematch' => '30.00',
            'min_odds_live' => '1.01',
            'max_odds_live' => '30.00',
            'max_selections' => 20,
            'live_close_minute' => 85,
            'cancel_minutes' => 0,
        ];
    }
}

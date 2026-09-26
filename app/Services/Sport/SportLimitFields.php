<?php

namespace App\Services\Sport;

class SportLimitFields
{
    /** @var list<string> */
    public const MIN = [
        'min_stake',
        'min_coupon_odds',
        'min_odds_prematch',
        'min_odds_live',
    ];

    /** @var list<string> */
    public const MAX = [
        'max_stake_general',
        'max_stake_single',
        'max_stake_live',
        'max_stake_per_fixture',
        'max_stake_per_outcome',
        'repeat_limit_single',
        'repeat_limit_combo',
        'daily_max',
        'max_coupon_odds',
        'max_payout_general',
        'max_payout_single',
        'max_payout_live',
        'max_payout_live_single',
        'max_odds_prematch',
        'max_odds_live',
        'max_selections',
        'live_close_minute',
        'cancel_minutes',
    ];

    /** @var list<string> */
    public const MONEY = [
        'min_stake',
        'max_stake_general',
        'max_stake_single',
        'max_stake_live',
        'max_stake_per_fixture',
        'max_stake_per_outcome',
        'repeat_limit_single',
        'repeat_limit_combo',
        'daily_max',
        'max_payout_general',
        'max_payout_single',
        'max_payout_live',
        'max_payout_live_single',
    ];

    /** @var list<string> */
    public const ODDS = [
        'min_coupon_odds',
        'max_coupon_odds',
        'min_odds_prematch',
        'max_odds_prematch',
        'min_odds_live',
        'max_odds_live',
    ];

    /** @var list<string> */
    public const INTS = [
        'max_selections',
        'live_close_minute',
        'cancel_minutes',
    ];

    /**
     * @return array<string, list<string>>
     */
    public static function groups(): array
    {
        return [
            'coupon' => ['cash_out_enabled', 'cancel_minutes', 'live_close_minute', 'max_selections', 'min_coupon_odds', 'max_coupon_odds'],
            'stake' => ['min_stake', 'max_stake_general', 'max_stake_single', 'max_stake_live', 'max_stake_per_fixture', 'max_stake_per_outcome', 'repeat_limit_single', 'repeat_limit_combo', 'daily_max'],
            'payout' => ['max_payout_general', 'max_payout_single', 'max_payout_live', 'max_payout_live_single'],
            'odds' => ['min_odds_prematch', 'max_odds_prematch', 'min_odds_live', 'max_odds_live'],
        ];
    }
}

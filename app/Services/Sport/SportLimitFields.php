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

    public static function isFloor(string $field): bool
    {
        return in_array($field, self::MIN, true);
    }

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

    public static function kind(string $field): string
    {
        if (in_array($field, self::MONEY, true)) {
            return 'money';
        }
        if (in_array($field, self::ODDS, true)) {
            return 'odds';
        }
        if (in_array($field, ['live_close_minute', 'cancel_minutes'], true)) {
            return 'minute';
        }

        return 'int';
    }

    /**
     * @return array{decimal: string, thousands: string}
     */
    public static function separators(string $locale): array
    {
        return in_array($locale, ['tr', 'de'], true)
            ? ['decimal' => ',', 'thousands' => '.']
            : ['decimal' => '.', 'thousands' => ','];
    }

    public static function canonical(string $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (in_array(self::kind($field), ['int', 'minute'], true)) {
            return (string) (int) $value;
        }

        return bcadd((string) $value, '0', 2);
    }

    public static function formatInput(string $field, string $canonical, string $decimal, string $thousands): string
    {
        if ($canonical === '') {
            return '';
        }
        if (self::kind($field) === 'odds') {
            return self::grouped($canonical, 2, '.', ',');
        }
        if (self::kind($field) === 'money') {
            return self::grouped($canonical, 2, $decimal, $thousands);
        }

        return self::grouped($canonical, 0, $decimal, $thousands);
    }

    public static function formatHint(string $field, string $canonical, string $decimal, string $thousands, string $symbol): string
    {
        if ($canonical === '') {
            return '';
        }
        $kind = self::kind($field);
        if ($kind === 'odds') {
            return self::grouped($canonical, 2, '.', ',');
        }
        if ($kind === 'money') {
            $whole = preg_match('/\.00$/', $canonical) === 1;

            return self::grouped($canonical, $whole ? 0 : 2, $decimal, $thousands).' '.$symbol;
        }

        return self::grouped($canonical, 0, $decimal, $thousands);
    }

    public static function suffix(string $field, string $symbol): string
    {
        return match (self::kind($field)) {
            'money' => $symbol,
            'odds' => 'x',
            'minute' => __('sport.panel.unit_minute'),
            default => '',
        };
    }

    private static function grouped(string $canonical, int $scale, string $decimal, string $thousands): string
    {
        $negative = str_starts_with($canonical, '-');
        $digits = ltrim($canonical, '-');
        if ($scale === 0) {
            $whole = explode('.', $digits)[0];
            $fraction = null;
        } else {
            [$whole, $fraction] = explode('.', bcadd($digits, '0', $scale));
        }
        $grouped = preg_replace('/\B(?=(\d{3})+(?!\d))/', $thousands, $whole) ?: $whole;
        $text = ($negative ? '-' : '').$grouped;
        if ($fraction !== null) {
            $text .= $decimal.$fraction;
        }

        return $text;
    }
}

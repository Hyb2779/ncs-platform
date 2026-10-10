<?php

namespace App\Support;

use App\Enums\Currency;
use Illuminate\Support\Facades\DB;

class PlatformSetting
{
    /** @return array<string, string> */
    public static function creditFeeRates(): array
    {
        $fallback = self::legacyCreditFeeRate();
        $stored = DB::table('platform_settings')
            ->whereIn('key', array_map(fn (Currency $currency): string => 'credit_fee_rate_'.$currency->value, Currency::cases()))
            ->pluck('value', 'key');

        $rates = [];
        foreach (Currency::cases() as $currency) {
            $rates[$currency->value] = bcadd((string) ($stored['credit_fee_rate_'.$currency->value] ?? $fallback), '0', 2);
        }

        return $rates;
    }

    public static function putCreditFeeRate(string $currency, string $rate): void
    {
        DB::table('platform_settings')->updateOrInsert(
            ['key' => 'credit_fee_rate_'.$currency],
            ['value' => bcadd($rate, '0', 2)],
        );
    }

    private static function legacyCreditFeeRate(): string
    {
        $value = DB::table('platform_settings')->where('key', 'credit_fee_rate')->value('value');

        return bcadd((string) ($value ?? '12'), '0', 2);
    }
}

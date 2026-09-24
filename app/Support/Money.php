<?php

namespace App\Support;

use App\Enums\Currency;

class Money
{
    public static function format(string $amount, Currency $currency): string
    {
        $value = number_format((float) $amount, 2, '.', '');
        $symbol = match ($currency) {
            Currency::Try => '₺',
            Currency::Usd => '$',
            Currency::Eur => '€',
        };

        return match (app()->getLocale()) {
            'tr' => self::grouped($value, ',', '.').' '.$symbol,
            'de' => self::grouped($value, ',', '.').' '.$symbol,
            'ar' => self::arabic($value).' '.$symbol,
            default => $symbol.self::grouped($value, '.', ','),
        };
    }

    private static function grouped(string $value, string $decimal, string $thousands): string
    {
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '00');
        $negative = str_starts_with($whole, '-');
        $digits = ltrim($whole, '-');
        $grouped = preg_replace('/\B(?=(\d{3})+(?!\d))/', $thousands, $digits) ?: $digits;

        return ($negative ? '-' : '').$grouped.$decimal.$fraction;
    }

    private static function arabic(string $value): string
    {
        $western = self::grouped($value, '٫', '٬');
        $digits = ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩', '-' => '-'];

        return strtr($western, $digits);
    }
}

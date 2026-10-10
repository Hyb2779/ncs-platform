<?php

namespace App\Enums;

enum Currency: string
{
    case Try = 'TRY';
    case Usd = 'USD';
    case Eur = 'EUR';
    case Aed = 'AED';

    public function symbol(): string
    {
        return match ($this) {
            self::Try => '₺',
            self::Usd => '$',
            self::Eur => '€',
            self::Aed => 'د.إ',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

<?php

namespace App\Enums;

enum Theme: string
{
    case Classic = 'classic';
    case Neon = 'neon';
    case Desert = 'desert';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

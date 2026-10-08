<?php

namespace App\Support;

/** Oyun adı araması: Türkçe büyük/küçük harf (İ/i, I/ı) duyarsız. */
class GameSearch
{
    public static function fold(string $value): string
    {
        $value = strtr($value, [
            'İ' => 'i',
            'I' => 'ı',
            'Ş' => 'ş',
            'Ğ' => 'ğ',
            'Ü' => 'ü',
            'Ö' => 'ö',
            'Ç' => 'ç',
        ]);

        return mb_strtolower($value, 'UTF-8');
    }

    public static function like(string $value): string
    {
        return '%'.addcslashes(self::fold($value), '%_\\').'%';
    }
}

<?php

namespace App\Enums;

enum UserRole: string
{
    case Owner = 'owner';
    case Superadmin = 'superadmin';
    case Bayi = 'bayi';
    case Uye = 'uye';

    public function childRole(): ?self
    {
        return match ($this) {
            self::Owner => self::Superadmin,
            self::Superadmin => self::Bayi,
            self::Bayi => self::Uye,
            self::Uye => null,
        };
    }
}

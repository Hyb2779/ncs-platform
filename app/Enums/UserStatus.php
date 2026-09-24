<?php

namespace App\Enums;

enum UserStatus: string
{
    case Active = 'active';
    case Passive = 'passive';
    case Banned = 'banned';
}

<?php

namespace App\Enums;

enum WalletProduct: string
{
    case Sport = 'sport';
    case Slot = 'slot';
    case LiveCasino = 'live_casino';
    case Transfer = 'transfer';
    case Bonus = 'bonus';
    case Adjustment = 'adjustment';
}

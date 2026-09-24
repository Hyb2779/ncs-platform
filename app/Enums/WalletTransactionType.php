<?php

namespace App\Enums;

enum WalletTransactionType: string
{
    case Mint = 'mint';
    case TransferIn = 'transfer_in';
    case TransferOut = 'transfer_out';
    case Bet = 'bet';
    case Win = 'win';
    case Refund = 'refund';
    case Bonus = 'bonus';
    case Adjustment = 'adjustment';
}

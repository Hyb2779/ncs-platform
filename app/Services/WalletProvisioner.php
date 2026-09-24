<?php

namespace App\Services;

use App\Enums\Currency;
use App\Enums\UserRole;
use App\Models\User;
use App\Models\Wallet;

class WalletProvisioner
{
    public function openFor(User $user): void
    {
        $currencies = $user->role === UserRole::Owner
            ? Currency::cases()
            : [$user->currency];

        foreach ($currencies as $currency) {
            Wallet::query()->firstOrCreate(
                [
                    'user_id' => $user->id,
                    'currency' => $currency,
                ],
                [
                    'balance' => 0,
                ],
            );
        }
    }
}

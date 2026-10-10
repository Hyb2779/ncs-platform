<?php

namespace App\Services;

use App\Enums\Currency;
use App\Models\User;
use App\Models\Wallet;

class WalletProvisioner
{
    public function openFor(User $user): void
    {
        $currencies = $user->isMultiCurrency()
            ? Currency::cases()
            : [$user->currency];

        foreach ($currencies as $currency) {
            $wallet = Wallet::query()->firstOrCreate(
                [
                    'user_id' => $user->id,
                    'currency' => $currency,
                ],
                [
                    'balance' => 0,
                ],
            );

            // Kredi üretme (eksiye düşme) yalnız kök owner'da. Alt owner, süperadmin ve bayi eksiye düşemez.
            if ($user->isRootOwner() && ! $wallet->allow_negative) {
                $wallet->forceFill(['allow_negative' => true])->save();
            }
        }
    }
}

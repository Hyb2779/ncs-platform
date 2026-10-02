<?php

namespace App\Services\Stats;

use App\Enums\Currency;
use App\Enums\UserRole;
use App\Enums\WalletTransactionType;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;

/**
 * Alt owner kredi ücreti: alt owner'ın DOĞRUDAN süperadminlerine verdiği kredi (transfer_out, brüt;
 * geri almalar düşülmez) × oran. Kök owner tahsilatları credit_fee_payments'ta. Kur çevrilmez.
 */
class CreditFees
{
    /**
     * @return list<array{owner: User, rate: string, rows: list<array{currency: string, issued: string, fee: string, paid: string, due: string}>}>
     */
    public function summary(): array
    {
        $out = [];
        $subs = User::query()->where('role', UserRole::Owner)->whereNotNull('parent_id')
            ->whereNotNull('credit_fee_rate')->orderBy('username')->get();

        foreach ($subs as $sub) {
            $saIds = User::query()->where('parent_id', $sub->id)->where('role', UserRole::Superadmin)->pluck('id')->all();
            $issued = WalletTransaction::query()
                ->join('wallets', 'wallets.id', '=', 'wallet_transactions.wallet_id')
                ->where('wallet_transactions.user_id', $sub->id)
                ->where('wallet_transactions.type', WalletTransactionType::TransferOut->value)
                ->whereIn('wallet_transactions.counterparty_user_id', $saIds ?: [0])
                ->groupBy('wallets.currency')
                ->selectRaw('wallets.currency as cur, SUM(ABS(wallet_transactions.amount)) as total')
                ->pluck('total', 'cur');
            $paid = DB::table('credit_fee_payments')->where('sub_owner_id', $sub->id)
                ->groupBy('currency')->selectRaw('currency as cur, SUM(amount) as total')->pluck('total', 'cur');

            $rate = (string) $sub->credit_fee_rate;
            $rows = [];
            foreach (Currency::cases() as $currency) {
                $c = $currency->value;
                $i = bcadd((string) ($issued[$c] ?? '0'), '0', 2);
                $fee = $this->round2(bcdiv(bcmul($i, $rate, 6), '100', 6));
                $p = bcadd((string) ($paid[$c] ?? '0'), '0', 2);
                $rows[] = ['currency' => $c, 'issued' => $i, 'fee' => $fee, 'paid' => $p, 'due' => bcsub($fee, $p, 2)];
            }
            $out[] = ['owner' => $sub, 'rate' => $rate, 'rows' => $rows];
        }

        return $out;
    }

    /** Yarım yukarı 2 basamak (tutarlar hep pozitif). */
    private function round2(string $value): string
    {
        return bcadd($value, '0.005', 2);
    }
}

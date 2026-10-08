<?php

namespace App\Services\Stats;

use App\Enums\Currency;
use App\Enums\UserRole;
use App\Enums\WalletTransactionType;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Kök owner'ın Volkan ekranı. Üretim = eksi bakiyenin yeni dip noktaları (credit_issues ile aynı kural).
 * Dağıtım = Volkan'ın kendi altındaki hesaplara yüklediği transferler; geri alış düşülmez.
 */
class VolkanCredit
{
    public function subject(): ?User
    {
        return User::query()
            ->where('role', UserRole::Owner)
            ->whereNotNull('parent_id')
            ->whereRaw('LOWER(username) = ?', ['volkan'])
            ->first();
    }

    /**
     * @return list<array{currency: Currency, produced: string, distributed: string}>
     */
    public function rows(User $sub, Carbon $fromLocal, Carbon $toLocal): array
    {
        $fromUtc = $fromLocal->copy()->startOfDay()->utc();
        $toUtc = $toLocal->copy()->endOfDay()->utc();
        $currencies = $sub->wallets()->pluck('currency')->unique()->values();
        if ($currencies->isEmpty()) {
            $currencies = collect([$sub->currency]);
        }

        $rows = [];
        foreach ($currencies as $currency) {
            $currency = $currency instanceof Currency ? $currency : Currency::from((string) $currency);
            $produced = $this->produced($sub, $currency, $fromUtc, $toUtc);
            $distributed = $this->distributed($sub, $currency, $fromUtc, $toUtc);
            if ($currency !== $sub->currency && bccomp($produced, '0', 2) === 0 && bccomp($distributed, '0', 2) === 0) {
                continue;
            }
            $rows[] = [
                'currency' => $currency,
                'produced' => $produced,
                'distributed' => $distributed,
            ];
        }

        return $rows;
    }

    private function produced(User $sub, Currency $currency, Carbon $fromUtc, Carbon $toUtc): string
    {
        $transactions = WalletTransaction::query()
            ->where('user_id', $sub->id)
            ->whereIn('wallet_id', $sub->wallets()->where('currency', $currency)->select('id'))
            ->orderBy('created_at')
            ->orderBy('sequence')
            ->orderBy('id')
            ->get(['balance_after', 'created_at']);

        $peak = '0.00';
        $period = '0.00';
        foreach ($transactions as $transaction) {
            $after = bcadd((string) $transaction->balance_after, '0', 2);
            $negative = bccomp($after, '0', 2) < 0 ? bcsub('0', $after, 2) : '0.00';
            if (bccomp($negative, $peak, 2) !== 1) {
                continue;
            }
            $increment = bcsub($negative, $peak, 2);
            $at = $transaction->created_at;
            if ($at !== null && $at->greaterThanOrEqualTo($fromUtc) && $at->lessThanOrEqualTo($toUtc)) {
                $period = bcadd($period, $increment, 2);
            }
            $peak = $negative;
        }

        return $period;
    }

    private function distributed(User $sub, Currency $currency, Carbon $fromUtc, Carbon $toUtc): string
    {
        $amounts = DB::table('wallet_transactions as t')
            ->join('wallets as w', 'w.id', '=', 't.wallet_id')
            ->where('t.user_id', $sub->id)
            ->where('w.currency', $currency->value)
            ->where('t.type', WalletTransactionType::TransferOut->value)
            ->where('t.created_at', '>=', $fromUtc)
            ->where('t.created_at', '<=', $toUtc)
            ->whereExists(function ($query) use ($sub) {
                $query->selectRaw('1')
                    ->from('users as u')
                    ->whereColumn('u.id', 't.counterparty_user_id')
                    ->where('u.path', 'like', $sub->path.'%')
                    ->where('u.id', '!=', $sub->id);
            })
            ->pluck('t.amount');

        $total = '0.00';
        foreach ($amounts as $amount) {
            $value = bcadd((string) $amount, '0', 2);
            if (bccomp($value, '0', 2) < 0) {
                $value = bcsub('0', $value, 2);
            }
            $total = bcadd($total, $value, 2);
        }

        return $total;
    }
}

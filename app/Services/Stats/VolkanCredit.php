<?php

namespace App\Services\Stats;

use App\Enums\Currency;
use App\Enums\UserRole;
use App\Enums\WalletTransactionType;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Kök owner'ın Volkan ekranı.
 * Üretim = credit_issues tutarları (WalletService, eksi dip yeniden hesaplanmaz).
 * Dağıtım = Volkan'ın kendi altındaki hesaplara yüklediği transfer_out; geri alış düşülmez.
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
     * @return list<array{currency: Currency, days: list<array{date: string, produced: string, distributed: string}>, produced: string, distributed: string}>
     */
    public function tables(User $sub, Carbon $fromLocal, Carbon $toLocal, string $zone): array
    {
        $fromUtc = $fromLocal->copy()->startOfDay()->utc();
        $toUtc = $toLocal->copy()->endOfDay()->utc();
        $currencies = $sub->wallets()->pluck('currency')->unique()->values();
        if ($currencies->isEmpty()) {
            $currencies = collect([$sub->currency]);
        }

        $tables = [];
        foreach ($currencies as $currency) {
            $currency = $currency instanceof Currency ? $currency : Currency::from((string) $currency);
            $produced = $this->producedByDay($sub, $currency, $fromUtc, $toUtc, $zone);
            $distributed = $this->distributedByDay($sub, $currency, $fromUtc, $toUtc, $zone);
            $days = [];
            $producedTotal = '0.00';
            $distributedTotal = '0.00';
            for ($day = $toLocal->copy()->startOfDay(); $day->greaterThanOrEqualTo($fromLocal->copy()->startOfDay()); $day->subDay()) {
                $key = $day->toDateString();
                $producedDay = $produced[$key] ?? '0.00';
                $distributedDay = $distributed[$key] ?? '0.00';
                $producedTotal = bcadd($producedTotal, $producedDay, 2);
                $distributedTotal = bcadd($distributedTotal, $distributedDay, 2);
                $days[] = [
                    'date' => $key,
                    'produced' => $producedDay,
                    'distributed' => $distributedDay,
                ];
            }
            if ($currency !== $sub->currency && bccomp($producedTotal, '0', 2) === 0 && bccomp($distributedTotal, '0', 2) === 0) {
                continue;
            }
            $tables[] = [
                'currency' => $currency,
                'days' => $days,
                'produced' => $producedTotal,
                'distributed' => $distributedTotal,
            ];
        }

        return $tables;
    }

    /**
     * @return array<string, string>
     */
    private function producedByDay(User $sub, Currency $currency, Carbon $fromUtc, Carbon $toUtc, string $zone): array
    {
        $rows = DB::table('credit_issues')
            ->where('user_id', $sub->id)
            ->where('currency', $currency->value)
            ->where('created_at', '>=', $fromUtc)
            ->where('created_at', '<=', $toUtc)
            ->get(['amount', 'created_at']);

        return $this->bucket($rows, 'amount', $zone);
    }

    /**
     * @return array<string, string>
     */
    private function distributedByDay(User $sub, Currency $currency, Carbon $fromUtc, Carbon $toUtc, string $zone): array
    {
        $rows = DB::table('wallet_transactions as t')
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
            ->get(['t.amount', 't.created_at']);

        return $this->bucket($rows, 'amount', $zone, true);
    }

    /**
     * @param  iterable<int, object>  $rows
     * @return array<string, string>
     */
    private function bucket(iterable $rows, string $amountKey, string $zone, bool $absolute = false): array
    {
        $days = [];
        foreach ($rows as $row) {
            $at = $row->created_at instanceof Carbon ? $row->created_at->copy() : Carbon::parse((string) $row->created_at, 'UTC');
            $key = $at->timezone($zone)->toDateString();
            $value = bcadd((string) $row->{$amountKey}, '0', 2);
            if ($absolute && bccomp($value, '0', 2) < 0) {
                $value = bcsub('0', $value, 2);
            }
            $days[$key] = bcadd($days[$key] ?? '0.00', $value, 2);
        }

        return $days;
    }
}

<?php

namespace App\Services\Stats;

use App\Enums\Currency;
use App\Enums\UserRole;
use App\Enums\WalletTransactionType;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Kök owner'ın alt owner ekranı.
 * Dağıtım = alt owner'ın kendi altındaki hesaplara yüklediği transfer_out; geri alış düşülmez.
 */
class VolkanCredit
{
    /** @return \Illuminate\Database\Eloquent\Collection<int, User> */
    public function subjects(): \Illuminate\Database\Eloquent\Collection
    {
        return User::query()
            ->where('role', UserRole::Owner)
            ->whereNotNull('parent_id')
            ->orderBy('username')
            ->get();
    }

    /**
     * Sıfır günler dönmez. En yeni gün başta.
     *
     * @return list<array{currency: Currency, days: list<array{date: string, distributed: string}>, distributed: string}>
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
            $distributed = $this->distributedByDay($sub, $currency, $fromUtc, $toUtc, $zone);
            $days = [];
            $distributedTotal = '0.00';
            for ($day = $toLocal->copy()->startOfDay(); $day->greaterThanOrEqualTo($fromLocal->copy()->startOfDay()); $day->subDay()) {
                $key = $day->toDateString();
                $distributedDay = $distributed[$key] ?? '0.00';
                $distributedTotal = bcadd($distributedTotal, $distributedDay, 2);
                if (bccomp($distributedDay, '0', 2) === 0) {
                    continue;
                }
                $days[] = [
                    'date' => $key,
                    'distributed' => $distributedDay,
                ];
            }
            if ($currency !== $sub->currency && bccomp($distributedTotal, '0', 2) === 0) {
                continue;
            }
            $tables[] = [
                'currency' => $currency,
                'days' => $days,
                'distributed' => $distributedTotal,
            ];
        }

        return $tables;
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

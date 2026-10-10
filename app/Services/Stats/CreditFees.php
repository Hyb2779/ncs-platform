<?php

namespace App\Services\Stats;

use App\Enums\Currency;
use App\Enums\UserRole;
use App\Enums\WalletTransactionType;
use App\Models\User;
use App\Support\PlatformSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Kök owner'ın alt owner'a yüklediği kredi (transfer_in) × para birimi oranı.
 * Geri çekilen düşülmez. Yanlış yükleme düzeltmesi (note bu önekle) düşülür.
 * Tahsilatı kök owner credit_fee_payments'a yazar. Kur çevrilmez.
 */
class CreditFees
{
    /** Bu notla kök owner'a dönen transfer, yüklenen krediden düşülür. */
    public const LOAD_CORRECTION_NOTE = 'credit-load-correction';
    /**
     * @return list<array{owner: User, rows: list<array{currency: string, rate: string, issued: string, fee: string, paid: string, due: string}>}>
     */
    public function summary(?Carbon $from = null, ?Carbon $to = null): array
    {
        $out = [];
        $subs = User::query()->where('role', UserRole::Owner)->whereNotNull('parent_id')->orderBy('username')->get();
        $rootId = User::query()->where('role', UserRole::Owner)->whereNull('parent_id')->value('id');
        $rates = PlatformSetting::creditFeeRates();

        foreach ($subs as $sub) {
            $issued = $rootId === null ? collect() : $this->byCurrency($sub->id, WalletTransactionType::TransferIn, $rootId, $from, $to);
            $corrected = $rootId === null ? collect() : $this->byCurrency($sub->id, WalletTransactionType::TransferOut, $rootId, $from, $to, self::LOAD_CORRECTION_NOTE);
            $paid = DB::table('credit_fee_payments')->where('sub_owner_id', $sub->id)
                ->when($from !== null, fn ($query) => $query->where('created_at', '>=', $from))
                ->when($to !== null, fn ($query) => $query->where('created_at', '<', $to))
                ->groupBy('currency')->selectRaw('currency as cur, SUM(amount) as total')->pluck('total', 'cur');

            $rows = [];
            foreach (Currency::cases() as $currency) {
                $c = $currency->value;
                $i = bcsub(bcadd((string) ($issued[$c] ?? '0'), '0', 2), bcadd((string) ($corrected[$c] ?? '0'), '0', 2), 2);
                if (bccomp($i, '0', 2) < 0) {
                    $i = '0.00';
                }
                $rate = $rates[$c];
                $fee = $this->round2(bcdiv(bcmul($i, $rate, 6), '100', 6));
                $p = bcadd((string) ($paid[$c] ?? '0'), '0', 2);
                $rows[] = ['currency' => $c, 'rate' => $rate, 'issued' => $i, 'fee' => $fee, 'paid' => $p, 'due' => bcsub($fee, $p, 2)];
            }
            $out[] = ['owner' => $sub, 'rows' => $rows];
        }

        return $out;
    }

    /** @return \Illuminate\Support\Collection<string, string> */
    private function byCurrency(int $userId, WalletTransactionType $type, int $counterpartyId, ?Carbon $from, ?Carbon $to, ?string $notePrefix = null)
    {
        $amount = $type === WalletTransactionType::TransferOut ? '-t.amount' : 't.amount';

        return DB::table('wallet_transactions as t')
            ->join('wallets as w', 'w.id', '=', 't.wallet_id')
            ->where('t.user_id', $userId)
            ->where('t.type', $type->value)
            ->where('t.counterparty_user_id', $counterpartyId)
            ->when($notePrefix !== null, fn ($query) => $query->where('t.note', 'like', $notePrefix.'%'))
            ->when($from !== null, fn ($query) => $query->where('t.created_at', '>=', $from))
            ->when($to !== null, fn ($query) => $query->where('t.created_at', '<', $to))
            ->groupBy('w.currency')->selectRaw('w.currency as cur, SUM('.$amount.') as total')->pluck('total', 'cur');
    }

    /** Yarım yukarı 2 basamak (tutarlar hep pozitif). */
    private function round2(string $value): string
    {
        return bcadd($value, '0.005', 2);
    }
}

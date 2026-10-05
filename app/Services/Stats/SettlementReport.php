<?php

namespace App\Services\Stats;

use App\Enums\UserRole;
use App\Models\TipoCoupon;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rapor Detay = haftalik tahsilat (04.10.2026). Odaktaki hesabin dogrudan altlari icin:
 * verilen/cekilen kredi (karsi taraf o hesabin agaci disinda), yatirilan/kazanan (PeriodReport, spor + casino),
 * bekleyen spor kuponlari; Genel = yatirilan - kazanan - bekleyen (pozitif: hesap zararda, uste odeyecek);
 * komisyon yalnizca bayi ve ustunde, oran varsa ve Genel > 0 (oyuncuda hesaplanmaz, Net = Genel).
 */
class SettlementReport
{
    private const SUMS = ['given', 'withdrawn', 'staked', 'won', 'pending', 'general', 'commission', 'net'];

    public function __construct(private readonly PeriodReport $period) {}

    /** @return array{rows: list<array<string, mixed>>, totals: array<string, mixed>} */
    public function build(User $focus, Carbon $fromUtc, Carbon $toUtc, string $currency): array
    {
        $children = User::withTrashed()->where('parent_id', $focus->id)->orderBy('username')->get()->keyBy('id');
        $stats = $this->period->build($focus, $fromUtc, $toUtc)['children'];
        $credit = $this->credit($children, $fromUtc, $toUtc, $currency);
        $pending = $this->pending($focus, $fromUtc, $toUtc, $currency);

        $rows = [];
        $totals = array_fill_keys(self::SUMS, '0.00') + ['staked_n' => 0, 'won_n' => 0, 'pending_n' => 0];
        foreach ($children as $id => $child) {
            $s = $stats[$id][$currency]['all'] ?? [];
            $staked = $this->m($s['turnover'] ?? 0);
            $won = $this->m($s['payout'] ?? 0);
            $pend = $pending[$id] ?? ['amount' => '0.00', 'count' => 0];
            $general = bcsub(bcsub($staked, $won, 2), $pend['amount'], 2);
            $role = $child->role instanceof \BackedEnum ? $child->role->value : (string) $child->role;
            $showCommission = $role !== UserRole::Uye->value;
            $rate = $this->m($child->commission_rate ?? 0);
            $hasRate = $showCommission && bccomp($rate, '0', 2) === 1;
            $commission = $hasRate && bccomp($general, '0', 2) === 1
                ? bcadd(bcdiv(bcmul($general, $rate, 6), '100', 6), '0.005', 2)
                : '0.00';

            $row = [
                'user' => $child,
                'show_commission' => $showCommission,
                'rate' => $hasRate ? rtrim(rtrim($rate, '0'), '.') : null,
                'given' => $credit[$id]['in'] ?? '0.00',
                'withdrawn' => $credit[$id]['out'] ?? '0.00',
                'staked' => $staked,
                'staked_n' => (int) ($s['bet_count'] ?? 0),
                'won' => $won,
                'won_n' => (int) ($s['win_count'] ?? 0),
                'pending' => $pend['amount'],
                'pending_n' => $pend['count'],
                'general' => $general,
                'commission' => $commission,
                'net' => bcsub($general, $commission, 2),
            ];
            $active = $row['staked_n'] > 0 || bccomp($row['given'], '0', 2) !== 0 || bccomp($row['withdrawn'], '0', 2) !== 0;
            if (! $active && $child->role === UserRole::Uye) {
                continue;
            }
            $rows[] = $row;
            foreach (self::SUMS as $k) {
                $totals[$k] = bcadd($totals[$k], $row[$k], 2);
            }
            foreach (['staked_n', 'won_n', 'pending_n'] as $k) {
                $totals[$k] += $row[$k];
            }
        }

        usort($rows, fn ($a, $b) => bccomp($b['staked'], $a['staked'], 2) ?: strcmp($a['user']->username, $b['user']->username));

        return [
            'rows' => $rows,
            'totals' => $totals,
            'show_commission' => collect($rows)->contains(fn ($row) => $row['show_commission']),
        ];
    }

    /** @return array<int, array{in?: string, out?: string}> */
    private function credit(Collection $children, Carbon $from, Carbon $to, string $currency): array
    {
        if ($children->isEmpty()) {
            return [];
        }
        $rows = DB::table('wallet_transactions as wt')
            ->join('wallets as w', 'w.id', '=', 'wt.wallet_id')
            ->leftJoin('users as cp', 'cp.id', '=', 'wt.counterparty_user_id')
            ->whereIn('wt.user_id', $children->keys()->all())
            ->where('w.currency', $currency)
            ->whereIn('wt.type', ['transfer_in', 'transfer_out'])
            ->where('wt.created_at', '>=', $from)
            ->where('wt.created_at', '<', $to)
            ->get(['wt.user_id', 'wt.type', 'wt.amount', 'cp.path as cp_path']);

        $out = [];
        foreach ($rows as $r) {
            $child = $children[(int) $r->user_id];
            // Hesabin kendi agacina (ornegin bayinin uyesine) yaptigi transfer tahsilat kredisi degildir.
            if ($r->cp_path !== null && str_starts_with((string) $r->cp_path, (string) $child->path)) {
                continue;
            }
            $k = $r->type === 'transfer_in' ? 'in' : 'out';
            $out[(int) $r->user_id][$k] = bcadd($out[(int) $r->user_id][$k] ?? '0.00', ltrim((string) $r->amount, '-'), 2);
        }

        return $out;
    }

    /** @return array<int, array{amount: string, count: int}> */
    private function pending(User $focus, Carbon $from, Carbon $to, string $currency): array
    {
        $rows = TipoCoupon::query()
            ->join('users as u', 'u.id', '=', 'tipo_coupons.user_id')
            ->where('u.path', 'like', $focus->path.'%')
            ->where('tipo_coupons.currency', $currency)
            ->where('tipo_coupons.placed_at', '>=', $from)
            ->where('tipo_coupons.placed_at', '<', $to)
            ->where(fn ($q) => $q->whereNull('tipo_coupons.status_label')->orWhereNotIn('tipo_coupons.status_label', TipoCoupon::SETTLED))
            ->get(['tipo_coupons.stake', 'u.path']);

        $out = [];
        $focusId = (string) $focus->id;
        foreach ($rows as $r) {
            $ids = array_values(array_filter(explode('/', (string) $r->path), fn ($v) => $v !== ''));
            $i = array_search($focusId, $ids, true);
            if ($i === false || ! isset($ids[$i + 1])) {
                continue;
            }
            $child = (int) $ids[$i + 1];
            $out[$child]['amount'] = bcadd($out[$child]['amount'] ?? '0.00', (string) $r->stake, 2);
            $out[$child]['count'] = ($out[$child]['count'] ?? 0) + 1;
        }

        return $out;
    }

    private function m(mixed $value): string
    {
        return bcadd((string) ($value ?? '0'), '0', 2);
    }
}

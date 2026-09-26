<?php

namespace App\Services\Stats;

use App\Enums\UserRole;
use App\Enums\WalletProduct;
use App\Enums\WalletTransactionType;
use App\Models\DailyStat;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DailyStatWriter
{
    /** @var list<string> */
    public const PRODUCTS = ['sport', 'slot', 'live_casino'];

    public function record(WalletTransaction $transaction): void
    {
        if (! $this->affects($transaction)) {
            return;
        }

        $member = $transaction->user ?? User::query()->find($transaction->user_id);
        if ($member === null || $member->role !== UserRole::Uye || $member->superadmin_id === null) {
            return;
        }

        $superadmin = User::query()->find($member->superadmin_id);
        if ($superadmin === null) {
            return;
        }

        $date = $transaction->created_at->copy()->timezone($superadmin->timezone)->toDateString();
        $closed = DailyStat::query()
            ->where('user_id', $superadmin->id)
            ->whereDate('stat_date', $date)
            ->whereNotNull('closed_at')
            ->exists();

        if ($closed) {
            $this->rewriteTree($superadmin, $date);
        }
    }

    public function rewriteTree(User $superadmin, string $statDate): void
    {
        $timezone = $superadmin->timezone ?: 'UTC';
        $start = Carbon::parse($statDate, $timezone)->startOfDay()->utc();
        $end = Carbon::parse($statDate, $timezone)->addDay()->startOfDay()->utc();

        $members = User::withTrashed()
            ->where('superadmin_id', $superadmin->id)
            ->where('role', UserRole::Uye)
            ->get(['id', 'parent_id', 'created_at']);
        $bayis = User::query()
            ->where('superadmin_id', $superadmin->id)
            ->where('role', UserRole::Bayi)
            ->get(['id']);
        $accountIds = array_merge([$superadmin->id], $bayis->pluck('id')->all());
        $memberIds = $members->pluck('id')->all();
        $bayiIds = array_fill_keys($bayis->pluck('id')->all(), true);

        $transactions = $memberIds === []
            ? collect()
            : WalletTransaction::query()
                ->whereIn('user_id', $memberIds)
                ->where('created_at', '>=', $start)
                ->where('created_at', '<', $end)
                ->whereIn('product', self::PRODUCTS)
                ->get(['user_id', 'type', 'product', 'amount']);

        $buckets = [];
        foreach ($transactions as $transaction) {
            if (! $this->affects($transaction)) {
                continue;
            }

            $targets = [$superadmin->id];
            $parentId = (int) $members->firstWhere('id', $transaction->user_id)?->parent_id;
            if (isset($bayiIds[$parentId])) {
                $targets[] = $parentId;
            }

            foreach ($targets as $accountId) {
                $this->accumulate($buckets, $accountId, $transaction);
            }
        }

        $newByAccount = array_fill_keys($accountIds, 0);
        foreach ($members as $member) {
            if ($member->created_at === null || $member->created_at->copy()->timezone($timezone)->toDateString() !== $statDate) {
                continue;
            }

            $newByAccount[$superadmin->id]++;
            $parentId = (int) $member->parent_id;
            if (isset($bayiIds[$parentId])) {
                $newByAccount[$parentId]++;
            }
        }

        $closedAt = DailyStat::query()
            ->where('user_id', $superadmin->id)
            ->whereDate('stat_date', $statDate)
            ->whereNotNull('closed_at')
            ->value('closed_at');

        DB::transaction(function () use ($superadmin, $statDate, $accountIds, $buckets, $newByAccount, $closedAt): void {
            $treeAccountIds = User::withTrashed()
                ->where('superadmin_id', $superadmin->id)
                ->where('role', UserRole::Bayi)
                ->pluck('id')
                ->push($superadmin->id);

            DailyStat::query()
                ->whereDate('stat_date', $statDate)
                ->whereIn('user_id', $treeAccountIds)
                ->delete();

            $now = now();
            $rows = [];
            foreach ($accountIds as $accountId) {
                $rows = array_merge($rows, $this->rowsFor(
                    $accountId,
                    $statDate,
                    $superadmin->currency->value,
                    $buckets,
                    $newByAccount[$accountId] ?? 0,
                    $closedAt,
                    $now,
                ));
            }

            if ($rows !== []) {
                DailyStat::query()->insert($rows);
            }
        });
    }

    public function close(User $superadmin, string $statDate): void
    {
        $this->rewriteTree($superadmin, $statDate);

        $ids = User::withTrashed()
            ->where('superadmin_id', $superadmin->id)
            ->where('role', UserRole::Bayi)
            ->pluck('id')
            ->push($superadmin->id);

        DailyStat::query()
            ->whereDate('stat_date', $statDate)
            ->whereIn('user_id', $ids)
            ->whereNull('closed_at')
            ->update(['closed_at' => now()]);
    }

    public function refreshOpen(User $superadmin): void
    {
        $today = now($superadmin->timezone ?: 'UTC');
        $this->rewriteTree($superadmin, $today->toDateString());
        $this->rewriteTree($superadmin, $today->copy()->subDay()->toDateString());
    }

    /**
     * @param  array<string, array{turnover: string, payout: string, bet_count: int, players: array<int, true>}>  $buckets
     * @return list<array<string, mixed>>
     */
    private function rowsFor(int $accountId, string $statDate, string $currency, array $buckets, int $newPlayers, mixed $closedAt, Carbon $now): array
    {
        $rows = [];
        $totalTurnover = '0.00';
        $totalPayout = '0.00';
        $totalBets = 0;
        $players = [];

        foreach (self::PRODUCTS as $product) {
            $bucket = $buckets[$accountId.'|'.$product] ?? $this->blank();
            $rows[] = $this->payload($accountId, $statDate, $currency, $product, $bucket, 0, $closedAt, $now);
            $totalTurnover = bcadd($totalTurnover, $bucket['turnover'], 2);
            $totalPayout = bcadd($totalPayout, $bucket['payout'], 2);
            $totalBets += $bucket['bet_count'];
            $players += $bucket['players'];
        }

        $rows[] = $this->payload($accountId, $statDate, $currency, 'all', [
            'turnover' => $totalTurnover,
            'payout' => $totalPayout,
            'bet_count' => $totalBets,
            'players' => $players,
        ], $newPlayers, $closedAt, $now);

        return $rows;
    }

    /**
     * @param  array{turnover: string, payout: string, bet_count: int, players: array<int, true>}  $bucket
     * @return array<string, mixed>
     */
    private function payload(int $accountId, string $statDate, string $currency, string $product, array $bucket, int $newPlayers, mixed $closedAt, Carbon $now): array
    {
        return [
            'stat_date' => $statDate,
            'user_id' => $accountId,
            'currency' => $currency,
            'product' => $product,
            'turnover' => $bucket['turnover'],
            'payout' => $bucket['payout'],
            'ggr' => bcsub($bucket['turnover'], $bucket['payout'], 2),
            'bet_count' => $bucket['bet_count'],
            'active_players' => count($bucket['players']),
            'new_players' => $newPlayers,
            'closed_at' => $closedAt,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function accumulate(array &$buckets, int $accountId, WalletTransaction $transaction): void
    {
        $product = $transaction->product instanceof WalletProduct ? $transaction->product->value : (string) $transaction->product;
        $type = $transaction->type instanceof WalletTransactionType ? $transaction->type : WalletTransactionType::from((string) $transaction->type);
        $key = $accountId.'|'.$product;
        $row = $buckets[$key] ?? $this->blank();
        $amount = bcadd((string) $transaction->amount, '0', 2);

        if ($type === WalletTransactionType::Bet) {
            $row['turnover'] = bcsub($row['turnover'], $amount, 2);
            $row['bet_count']++;
            $row['players'][$transaction->user_id] = true;
        } elseif ($type === WalletTransactionType::Refund) {
            $row['turnover'] = bcsub($row['turnover'], $amount, 2);
        } elseif ($type === WalletTransactionType::Win) {
            $row['payout'] = bcadd($row['payout'], $amount, 2);
        } elseif ($type === WalletTransactionType::Adjustment && in_array($product, StatRules::PAYOUT_ADJUSTMENT_PRODUCTS, true)) {
            $row['payout'] = bcadd($row['payout'], $amount, 2);
        }

        $buckets[$key] = $row;
    }

    /**
     * @return array{turnover: string, payout: string, bet_count: int, players: array<int, true>}
     */
    private function blank(): array
    {
        return [
            'turnover' => '0.00',
            'payout' => '0.00',
            'bet_count' => 0,
            'players' => [],
        ];
    }

    private function affects(WalletTransaction $transaction): bool
    {
        $product = $transaction->product instanceof WalletProduct ? $transaction->product->value : (string) $transaction->product;
        $type = $transaction->type instanceof WalletTransactionType ? $transaction->type : WalletTransactionType::from((string) $transaction->type);

        if (! in_array($product, self::PRODUCTS, true)) {
            return false;
        }

        return in_array($type, [
            WalletTransactionType::Bet,
            WalletTransactionType::Win,
            WalletTransactionType::Refund,
            WalletTransactionType::Adjustment,
        ], true);
    }
}

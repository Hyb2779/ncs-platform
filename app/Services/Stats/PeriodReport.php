<?php

namespace App\Services\Stats;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Faz 6: donem raporu. Defterden (wallet_transactions) canli hesaplanir, daily_stats kullanilmaz:
 * para birimi islem bazinda wallets.currency'den gelir. Kural DailyStatWriter ile ayni:
 * ciro = -(bahis + iade), odeme = kazanc + oyun urunlu duzeltme, GGR = ciro - odeme.
 */
class PeriodReport
{
    public const PRODUCTS = ['sport', 'slot', 'live_casino'];

    private const PROVIDER_NAMES = ['goldpalace' => 'GoldPalace', 'romaspin' => 'RomaSpin', 'gamex' => '1GameX'];

    /**
     * @return array{totals: array<string, array<string, array>>, children: array<int, array<string, array>>}
     */
    public function build(User $focus, Carbon $fromUtc, Carbon $toUtc): array
    {
        $totals = [];
        $children = [];
        $prefix = (string) $focus->path;

        foreach ($this->memberRows($focus, $fromUtc, $toUtc) as $row) {
            $currency = (string) $row->currency;
            $product = (string) $row->product;
            $metrics = [
                'turnover' => $this->money($row->turnover),
                'payout' => $this->money($row->payout),
                'bet_count' => (int) $row->bet_count,
                'players' => (int) $row->bet_count > 0 ? [(int) $row->user_id => true] : [],
            ];

            $this->add($totals[$currency][$product], $metrics);
            $this->add($totals[$currency]['all'], $metrics);

            $childId = $this->childOf($prefix, (string) $row->path, (int) $row->user_id);
            $this->add($children[$childId][$currency]['all'], $metrics);
            $this->add($children[$childId][$currency][$product === 'sport' ? 'sport' : 'casino'], $metrics);
        }

        return ['totals' => $this->finish($totals), 'children' => $this->finish($children)];
    }

    /**
     * Saglayici (aggregator, anahtar oneki) x marka x kategori. Mini burada ayri cikar.
     *
     * @return list<array<string, mixed>>
     */
    public function providers(User $focus, Carbon $fromUtc, Carbon $toUtc): array
    {
        $buckets = [];

        $this->baseQuery($focus, $fromUtc, $toUtc)
            ->whereIn('wt.product', ['slot', 'live_casino'])
            ->whereIn('wt.type', ['bet', 'win', 'refund'])
            ->select(['wt.id', 'wt.user_id', 'wt.type', 'wt.product', 'wt.amount', 'wt.idempotency_key', 'w.currency'])
            ->chunkById(2000, function ($rows) use (&$buckets): void {
                $games = $this->gamesFor($rows->pluck('idempotency_key')->all());
                foreach ($rows as $row) {
                    $key = (string) $row->idempotency_key;
                    $provider = str_contains($key, ':') ? strstr($key, ':', true) : '?';
                    $game = $games[$key] ?? null;
                    $category = $game === null
                        ? ($row->product === 'live_casino' ? 'live' : 'slot')
                        : ($game->category === 'mini' ? 'mini' : ((bool) $game->is_live ? 'live' : 'slot'));
                    $vendor = $game->vendor ?? null;
                    $amount = $this->money($row->amount);
                    $metrics = ['turnover' => '0.00', 'payout' => '0.00', 'bet_count' => 0, 'players' => []];

                    if ($row->type === 'win') {
                        $metrics['payout'] = $amount;
                    } else {
                        $metrics['turnover'] = bcsub('0', $amount, 2);
                        if ($row->type === 'bet') {
                            $metrics['bet_count'] = 1;
                            $metrics['players'][(int) $row->user_id] = true;
                        }
                    }

                    $id = $row->currency.'|'.$provider.'|'.($vendor ?? '').'|'.$category;
                    $buckets[$id] ??= ['currency' => (string) $row->currency, 'provider' => $provider, 'vendor' => $vendor, 'category' => $category];
                    $this->add($buckets[$id]['m'], $metrics);
                }
            }, 'wt.id', 'id');

        $out = [];
        foreach ($buckets as $bucket) {
            $m = $this->finishOne($bucket['m']);
            unset($bucket['m']);
            $out[] = $bucket + $m + ['provider_name' => self::PROVIDER_NAMES[$bucket['provider']] ?? ucfirst($bucket['provider'])];
        }
        usort($out, fn ($a, $b) => [$a['currency'], -(float) $a['turnover']] <=> [$b['currency'], -(float) $b['turnover']]);

        return $out;
    }

    /** @return list<object> */
    private function memberRows(User $focus, Carbon $fromUtc, Carbon $toUtc): array
    {
        $adjust = "'".implode("','", StatRules::PAYOUT_ADJUSTMENT_PRODUCTS)."'";

        return $this->baseQuery($focus, $fromUtc, $toUtc)
            ->whereIn('wt.product', self::PRODUCTS)
            ->whereIn('wt.type', ['bet', 'win', 'refund', 'adjustment'])
            ->groupBy('wt.user_id', 'u.path', 'w.currency', 'wt.product')
            ->selectRaw('wt.user_id, u.path, w.currency, wt.product')
            ->selectRaw("SUM(CASE WHEN wt.type IN ('bet','refund') THEN -wt.amount ELSE 0 END) AS turnover")
            ->selectRaw("SUM(CASE WHEN wt.type = 'win' OR (wt.type = 'adjustment' AND wt.product IN ($adjust)) THEN wt.amount ELSE 0 END) AS payout")
            ->selectRaw("SUM(CASE WHEN wt.type = 'bet' THEN 1 ELSE 0 END) AS bet_count")
            ->get()
            ->all();
    }

    private function baseQuery(User $focus, Carbon $fromUtc, Carbon $toUtc)
    {
        $query = DB::table('wallet_transactions as wt')
            ->join('users as u', 'u.id', '=', 'wt.user_id')
            ->join('wallets as w', 'w.id', '=', 'wt.wallet_id')
            ->where('u.role', UserRole::Uye->value)
            ->where('wt.created_at', '>=', $fromUtc)
            ->where('wt.created_at', '<', $toUtc);

        if (! $focus->isRootOwner()) {
            $query->where('u.path', 'like', $focus->path.'%');
        }

        return $query;
    }

    /**
     * Anahtar = provider:provider_transaction_id. Saglayiciya gore gruplanir ki
     * (provider, provider_transaction_id) unique index'i kullanilsin.
     *
     * @param  list<string>  $keys
     * @return array<string, object>
     */
    private function gamesFor(array $keys): array
    {
        $byProvider = [];
        foreach ($keys as $key) {
            if (str_contains((string) $key, ':')) {
                $byProvider[strstr($key, ':', true)][] = substr($key, strpos($key, ':') + 1);
            }
        }

        $games = [];
        foreach ($byProvider as $provider => $ids) {
            DB::table('game_rounds as gr')
                ->leftJoin('casino_games as cg', 'cg.id', '=', 'gr.game_id')
                ->where('gr.provider', $provider)
                ->whereIn('gr.provider_transaction_id', array_values(array_unique($ids)))
                ->get(['gr.provider', 'gr.provider_transaction_id', 'cg.vendor', 'cg.category', 'cg.is_live'])
                ->each(function ($r) use (&$games): void {
                    $games[$r->provider.':'.$r->provider_transaction_id] = $r;
                });
        }

        return $games;
    }

    private function childOf(string $prefix, string $path, int $memberId): int
    {
        $rest = str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
        $first = (int) strtok(trim($rest, '/'), '/');

        return $first > 0 ? $first : $memberId;
    }

    private function add(?array &$target, array $m): void
    {
        $target ??= ['turnover' => '0.00', 'payout' => '0.00', 'bet_count' => 0, 'players' => []];
        $target['turnover'] = bcadd($target['turnover'], $m['turnover'], 2);
        $target['payout'] = bcadd($target['payout'], $m['payout'], 2);
        $target['bet_count'] += $m['bet_count'];
        $target['players'] += $m['players'];
    }

    private function finish(array $tree): array
    {
        foreach ($tree as $k => $v) {
            $tree[$k] = isset($v['players']) && is_array($v['players']) && isset($v['turnover']) ? $this->finishOne($v) : $this->finish($v);
        }

        return $tree;
    }

    /** @return array{turnover: string, payout: string, ggr: string, bet_count: int, players: int} */
    private function finishOne(array $m): array
    {
        return [
            'turnover' => $m['turnover'],
            'payout' => $m['payout'],
            'ggr' => bcsub($m['turnover'], $m['payout'], 2),
            'bet_count' => $m['bet_count'],
            'players' => count($m['players']),
        ];
    }

    /** MariaDB decimal string doner, SQLite float; ikisi de 2 basamaga sabitlenir. */
    private function money(mixed $value): string
    {
        if (is_string($value) && preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            return bcadd($value, '0', 2);
        }

        return number_format(round((float) $value, 2), 2, '.', '');
    }
}

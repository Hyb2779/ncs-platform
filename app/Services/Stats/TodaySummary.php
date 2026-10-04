<?php

namespace App\Services\Stats;

use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Genel Bakis "Bugun / Bu hafta" blogu (Lanus benzeri). PeriodReport kurali, goruntuleyenin agaci, tek para birimi.
 * Spor Tipo iframe'inden gelir: yatirilan = debit adedi/tutari, kazanan = credit adedi/tutari.
 * Kaybeden (bugun oynanan) ve bekleyen (su an acik) kuponlar tipo_coupons'tan (NCS koprusu senkronu).
 */
class TodaySummary
{
    public function __construct(private readonly PeriodReport $report) {}

    public function for(User $viewer, ?string $requested = null): array
    {
        $zone = $viewer->timezone ?: 'Europe/Istanbul';
        $now = Carbon::now($zone);
        $todayFrom = $now->copy()->startOfDay()->utc();
        $end = $now->copy()->addDay()->startOfDay()->utc();
        $weekFrom = $now->copy()->startOfWeek()->utc();

        $owned = $viewer->wallets()->toBase()->pluck('currency')->map(fn ($c) => (string) $c)->all();
        $currency = in_array($requested, $owned, true) ? $requested : $viewer->currency->value;

        $today = $this->report->build($viewer, $todayFrom, $end)['totals'][$currency] ?? [];
        $week = $this->report->build($viewer, $weekFrom, $end)['totals'][$currency] ?? [];
        $nets = $this->report->playerNets($viewer, $weekFrom, $end, $currency);
        $names = User::withTrashed()->whereIn('id', array_column($nets, 'user_id') ?: [0])->pluck('username', 'id');

        $tipo = \App\Models\TipoCoupon::query()
            ->whereIn('user_id', User::query()->subtreeOf($viewer)->select('id'))
            ->where('currency', $currency);
        $lost = (clone $tipo)->whereIn('status_label', \App\Models\TipoCoupon::LABELS['lost'])
            ->where('placed_at', '>=', $todayFrom)->where('placed_at', '<', $end)
            ->selectRaw('COUNT(*) as c, COALESCE(SUM(stake), 0) as s')->first();
        $pending = (clone $tipo)
            ->where(fn ($q) => $q->whereNull('status_label')->orWhereNotIn('status_label', \App\Models\TipoCoupon::SETTLED))
            ->selectRaw('COUNT(*) as c, COALESCE(SUM(stake), 0) as s')->first();

        $pick = fn (array $t, string $p, string $f) => $t[$p][$f] ?? ($f === 'bet_count' || $f === 'win_count' || $f === 'players' ? 0 : '0.00');
        $casino = fn (array $t, string $f) => bcadd((string) $pick($t, 'slot', $f), (string) $pick($t, 'live_casino', $f), 2);
        $top = $nets[0] ?? null;
        $bottom = $nets === [] ? null : $nets[array_key_last($nets)];

        return [
            'currency' => $currency,
            'today_ggr' => $pick($today, 'all', 'ggr'),
            'week_ggr' => $pick($week, 'all', 'ggr'),
            'sport_bets' => ['count' => $pick($today, 'sport', 'bet_count'), 'amount' => $pick($today, 'sport', 'turnover')],
            'sport_wins' => ['count' => $pick($today, 'sport', 'win_count'), 'amount' => $pick($today, 'sport', 'payout')],
            'sport_lost' => ['count' => (int) ($lost->c ?? 0), 'amount' => bcadd((string) ($lost->s ?? '0'), '0', 2)],
            'sport_pending' => ['count' => (int) ($pending->c ?? 0), 'amount' => bcadd((string) ($pending->s ?? '0'), '0', 2)],
            'sport_ggr' => $pick($today, 'sport', 'ggr'),
            'week_sport_ggr' => $pick($week, 'sport', 'ggr'),
            'casino_turnover' => $casino($today, 'turnover'),
            'casino_ggr' => $casino($today, 'ggr'),
            'week_casino_ggr' => $casino($week, 'ggr'),
            'players' => $pick($today, 'all', 'players'),
            'top_winner' => $top !== null && bccomp($top['net'], '0', 2) > 0 ? ['name' => $names[$top['user_id']] ?? '#'.$top['user_id'], 'net' => $top['net']] : null,
            'top_loser' => $bottom !== null && bccomp($bottom['net'], '0', 2) < 0 ? ['name' => $names[$bottom['user_id']] ?? '#'.$bottom['user_id'], 'net' => bcsub('0', $bottom['net'], 2)] : null,
        ];
    }
}

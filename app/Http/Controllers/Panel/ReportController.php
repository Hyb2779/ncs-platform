<?php

namespace App\Http\Controllers\Panel;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Stats\PeriodReport;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Faz 6: donem secmeli raporlar. Owner (kok + alt) ve superadmin; bayi 404.
 * ?user=<id> secili hesabin altina iner (agac disi 404), ?tab=providers saglayici kirilimi.
 */
class ReportController extends Controller
{
    public const PERIODS = ['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'custom'];

    private const MAX_DAYS = 92;

    public function index(Request $request, PeriodReport $reports): View
    {
        $actor = $request->user();
        // Bayi de gorur (04.10, Blackeagle): sadece kendi agaci, kirilim oyuncu bazinda.
        abort_unless(in_array($actor->role, [UserRole::Owner, UserRole::Superadmin, UserRole::Bayi], true), 404);
        $zone = $actor->timezone ?: 'Europe/Istanbul';

        [$period, $fromLocal, $toLocal] = $this->period($request, $zone);
        $fromUtc = $fromLocal->copy()->utc();
        $toUtc = $toLocal->copy()->addDay()->startOfDay()->utc();

        $focus = $actor;
        if ($request->filled('user')) {
            $candidate = User::withTrashed()->find((int) $request->query('user'));
            abort_unless($candidate !== null && $candidate->role !== UserRole::Uye && $candidate->isInSubtreeOf($actor), 404);
            $focus = $candidate;
        }

        // 04.10: Rapor Detay = haftalik tahsilat (verilen/cekilen kredi, yatirilan/kazanan/bekleyen, genel, komisyon, net).
        $currency = $focus->currency instanceof \BackedEnum ? $focus->currency->value : (string) $focus->currency;
        $report = app(\App\Services\Stats\SettlementReport::class)->build($focus, $fromUtc, $toUtc, $currency);

        return view('panel.reports.index', [
            'period' => $period,
            'from' => $fromLocal->toDateString(),
            'to' => $toLocal->toDateString(),
            'focus' => $focus,
            'trail' => $this->trail($actor, $focus),
            'rows' => $report['rows'],
            'totals' => $report['totals'],
            'currency' => $focus->currency,
            'periods' => self::PERIODS,
        ]);
    }

    /** @return array{0: string, 1: Carbon, 2: Carbon} yerel gun baslangici, yerel son gun (dahil) */
    private function period(Request $request, string $zone): array
    {
        $now = Carbon::now($zone);
        $period = in_array($request->query('period'), self::PERIODS, true) ? (string) $request->query('period') : 'this_week';

        if ($period === 'custom') {
            try {
                $from = Carbon::parse((string) $request->query('from'), $zone)->startOfDay();
                $to = Carbon::parse((string) $request->query('to'), $zone)->startOfDay();
            } catch (\Throwable) {
                $from = $now->copy()->startOfDay();
                $to = $from->copy();
            }
            if ($to->lt($from)) {
                [$from, $to] = [$to, $from];
            }
            if ($from->diffInDays($to) >= self::MAX_DAYS) {
                $from = $to->copy()->subDays(self::MAX_DAYS - 1);
            }

            return [$period, $from, $to];
        }

        $today = $now->copy()->startOfDay();

        return match ($period) {
            'yesterday' => [$period, $today->copy()->subDay(), $today->copy()->subDay()],
            'this_week' => [$period, $today->copy()->startOfWeek(), $today],
            'last_week' => [$period, $today->copy()->subWeek()->startOfWeek(), $today->copy()->subWeek()->endOfWeek()->startOfDay()],
            'this_month' => [$period, $today->copy()->startOfMonth(), $today],
            'last_month' => [$period, $today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            default => [$period, $today, $today],
        };
    }

    /**
     * Bayi seviyesinde sadece oynayan oyuncular; ustunde tum dogrudan altlar (oynamayan 0 ile).
     *
     * @return list<array{user: User, stats: array}>
     */
    private function children(User $focus, array $stats): array
    {
        $users = User::withTrashed()->where('parent_id', $focus->id)->where('role', '!=', UserRole::Uye)->get();
        $missing = array_diff(array_keys($stats), $users->pluck('id')->all());
        if ($missing !== []) {
            $users = $users->concat(User::withTrashed()->whereIn('id', $missing)->get());
        }

        $rows = $users->map(fn (User $u) => ['user' => $u, 'stats' => $stats[$u->id] ?? []])->all();
        usort($rows, fn ($a, $b) => $this->sortKey($b) <=> $this->sortKey($a) ?: strcmp($a['user']->username, $b['user']->username));

        return $rows;
    }

    private function sortKey(array $row): float
    {
        return array_sum(array_map(fn ($c) => (float) ($c['all']['turnover'] ?? 0), $row['stats']));
    }

    /** @return list<User> actor'dan focus'a kadar (ikisi dahil) */
    private function trail(User $actor, User $focus): array
    {
        if ($actor->id === $focus->id) {
            return [$actor];
        }
        $ids = array_map('intval', array_filter(explode('/', (string) $focus->path)));
        $start = array_search($actor->id, $ids, true);
        $ids = $start === false ? [$focus->id] : array_slice($ids, $start + 1);
        $users = User::withTrashed()->whereIn('id', $ids)->get()->keyBy('id');

        return array_values(array_filter(array_merge([$actor], array_map(fn ($id) => $users[$id] ?? null, $ids))));
    }
}

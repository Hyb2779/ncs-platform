<?php

namespace App\Http\Controllers\Panel;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Stats\PeriodReport;
use App\Support\GgrPayload;
use App\Support\ReportPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Faz 6: donem secmeli raporlar. Owner (kok + alt) ve superadmin; bayi 404.
 * ?user=<id> secili hesabin altina iner (agac disi 404), ?tab=providers saglayici kirilimi.
 */
class ReportController extends Controller
{
    public const PERIODS = ReportPeriod::PERIODS;

    public function index(Request $request, PeriodReport $reports): View|JsonResponse
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
            abort_if($candidate === null, 404);
            abort_unless($candidate->isInSubtreeOf($actor), 404);
            abort_unless($candidate->role !== UserRole::Uye, 404);
            $focus = $candidate;
        }

        // 04.10: Rapor Detay = haftalik tahsilat (verilen/cekilen kredi, yatirilan/kazanan/bekleyen, genel, komisyon, net).
        $currency = $focus->currency instanceof \BackedEnum ? $focus->currency->value : (string) $focus->currency;
        $report = app(\App\Services\Stats\SettlementReport::class)->build($focus, $fromUtc, $toUtc, $currency);

        if ($request->wantsJson() || $request->boolean('export')) {
            $payload = [
                'period' => $period,
                'from' => $fromLocal->toDateString(),
                'to' => $toLocal->toDateString(),
                'currency' => $currency,
                'rows' => array_map(fn (array $row): array => $this->publicRow($actor, $row), $report['rows']),
                'totals' => $this->publicTotals($actor, $report['totals']),
            ];
            $response = response()->json(GgrPayload::present($actor, $payload));
            if ($request->boolean('export')) {
                $response->header('Content-Disposition', 'attachment; filename="report.json"');
            }

            return $response;
        }

        return view('panel.reports.index', [
            'period' => $period,
            'from' => $fromLocal->toDateString(),
            'to' => $toLocal->toDateString(),
            'focus' => $focus,
            'trail' => $this->trail($actor, $focus),
            'rows' => $report['rows'],
            'totals' => $report['totals'],
            'currency' => $focus->currency,
            'showCommission' => $report['show_commission'],
            'showCredit' => $report['show_credit'],
        ]);
    }

    /** @param  array<string, mixed>  $row */
    private function publicRow(User $actor, array $row): array
    {
        $public = [
            'username' => $row['user']->username,
            'role' => $row['user']->role instanceof \BackedEnum ? $row['user']->role->value : (string) $row['user']->role,
            'given' => $row['given'],
            'withdrawn' => $row['withdrawn'],
            'staked' => $row['staked'],
            'staked_n' => $row['staked_n'],
            'won' => $row['won'],
            'won_n' => $row['won_n'],
            'pending' => $row['pending'],
            'pending_n' => $row['pending_n'],
        ];
        if (GgrPayload::visible($actor)) {
            $public['general'] = $row['general'];
            $public['commission'] = $row['commission'];
            $public['net'] = $row['net'];
        }

        return $public;
    }

    /** @param  array<string, mixed>  $totals */
    private function publicTotals(User $actor, array $totals): array
    {
        if (GgrPayload::visible($actor)) {
            return $totals;
        }
        unset($totals['general'], $totals['commission'], $totals['net']);

        return $totals;
    }

    /** @return array{0: string, 1: Carbon, 2: Carbon} yerel gun baslangici, yerel son gun (dahil) */
    private function period(Request $request, string $zone): array
    {
        return ReportPeriod::resolveDetail($request, $zone);
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

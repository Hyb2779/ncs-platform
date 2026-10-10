<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\TipoCoupon;
use App\Models\User;
use App\Services\HierarchyService;
use App\Services\Stats\PeriodReport;
use App\Support\GgrPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/** Hesap ozeti: bakiye, donem spor/casino, acik (riskli) ve son Wegas Spor kuponlari. Agac disi ve kendisi 404. */
class MemberProfileController extends Controller
{
    public function show(Request $request, User $user, HierarchyService $hierarchy, PeriodReport $report): View|JsonResponse
    {
        $actor = $request->user();
        $member = $hierarchy->findInSubtree($actor, $user->id);
        abort_if($member->id === $actor->id, 404);

        $range = in_array($request->query('range'), ['today', '7', '30'], true) ? (string) $request->query('range') : '30';
        $now = Carbon::now($actor->timezone ?: 'Europe/Istanbul');
        $from = match ($range) {
            'today' => $now->copy()->startOfDay(),
            '7' => $now->copy()->subDays(6)->startOfDay(),
            default => $now->copy()->subDays(29)->startOfDay(),
        };
        $fromUtc = $from->copy()->utc();
        $toUtc = $now->copy()->addDay()->startOfDay()->utc();

        $currency = $member->currency->value;
        $totals = $report->build($member, $fromUtc, $toUtc)['totals'][$currency] ?? [];
        $get = fn (string $p, string $f) => $totals[$p][$f] ?? (in_array($f, ['bet_count', 'win_count', 'players'], true) ? 0 : '0.00');
        $casino = fn (string $f) => bcadd((string) $get('slot', $f), (string) $get('live_casino', $f), 2);

        $coupons = TipoCoupon::query()->whereIn('user_id', User::query()->subtreeOf($member)->select('id'));
        $open = (clone $coupons)->where(fn ($q) => $q->whereNull('status_label')->orWhereNotIn('status_label', TipoCoupon::SETTLED));
        $lost = (clone $coupons)->whereIn('status_label', TipoCoupon::LABELS['lost'])
            ->where('placed_at', '>=', $fromUtc)->where('placed_at', '<', $toUtc);

        $viewData = [
            'member' => $member->loadMissing('parent'),
            'range' => $range,
            'currency' => $member->currency,
            'balance' => (string) ($member->wallets()->where('currency', $currency)->value('balance') ?? '0'),
            'online' => Cache::has('presence:'.$member->id),
            'sport' => [
                'turnover' => $get('sport', 'turnover'),
                'bets' => $get('sport', 'bet_count'),
                'payout' => $get('sport', 'payout'),
                'wins' => $get('sport', 'win_count'),
                'lost' => bcadd((string) (clone $lost)->sum('stake'), '0', 2),
                'lost_count' => (clone $lost)->count(),
                'pending' => bcadd((string) (clone $open)->sum('stake'), '0', 2),
                'pending_count' => (clone $open)->count(),
                ...($actor->role === \App\Enums\UserRole::Owner ? ['ggr' => $get('sport', 'ggr')] : []),
            ],
            'casino' => [
                'turnover' => $casino('turnover'),
                'payout' => $casino('payout'),
                ...($actor->role === \App\Enums\UserRole::Owner ? ['ggr' => $casino('ggr')] : []),
            ],
            'openCoupons' => (clone $open)->orderByDesc('potential_win')->limit(20)->get(),
            'recentCoupons' => (clone $coupons)->orderByDesc('placed_at')->limit(20)->get(),
        ];

        if ($request->wantsJson() || $request->boolean('export')) {
            $response = response()->json(GgrPayload::present($actor, [
                'username' => $member->username,
                'range' => $range,
                'currency' => $currency,
                'balance' => $viewData['balance'],
                'sport' => $viewData['sport'],
                'casino' => $viewData['casino'],
            ]));
            if ($request->boolean('export')) {
                $response->header('Content-Disposition', 'attachment; filename="member.json"');
            }

            return $response;
        }

        return view('panel.users.show', $viewData);
    }
}

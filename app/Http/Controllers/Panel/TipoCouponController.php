<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\TipoCoupon;
use App\Models\User;
use App\Services\Sport\NcsBridge;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Wegas Spor (Tipo) kuponlari: Tum Kuponlar + detay, agac kapsamli. */
class TipoCouponController extends Controller
{
    public function index(Request $request): View
    {
        $query = TipoCoupon::query()->whereIn('user_id', User::query()->subtreeOf($request->user())->select('id'));
        $this->filter($request, $query);

        return view('panel.tipo_coupons.index', [
            'coupons' => (clone $query)->with('user')->orderByDesc('placed_at')->limit(200)->get(),
            'cards' => $this->cards($query, $request),
        ]);
    }

    public function show(Request $request, TipoCoupon $tipoCoupon, NcsBridge $bridge): View
    {
        $tipoCoupon->loadMissing('user');
        abort_if($tipoCoupon->user === null, 404);
        abort_unless($tipoCoupon->user->isInSubtreeOf($request->user()), 404);

        $tipoCoupon->ensureDetail($bridge);

        return view('panel.tipo_coupons.show', ['coupon' => $tipoCoupon]);
    }

    /** Bahis Yogunlugu: mac bazinda kupon sayisi/tutar ve tahmin kirilimi (agac kapsamli). */
    public function density(Request $request): View
    {
        $viewer = $request->user();
        $mode = in_array($request->query('mode'), ['open', 'today', '7'], true) ? (string) $request->query('mode') : 'open';
        $sort = $request->query('sort') === 'stake' ? 'stake' : 'coupons';
        $now = \Illuminate\Support\Carbon::now($viewer->timezone ?: 'Europe/Istanbul');

        $base = \App\Models\TipoSelection::query()
            ->join('tipo_coupons as c', 'c.id', '=', 'tipo_selections.tipo_coupon_id')
            ->whereIn('c.user_id', User::query()->subtreeOf($viewer)->select('id'))
            ->where('c.currency', $viewer->currency->value);
        if ($mode === 'open') {
            $base->where(fn ($q) => $q->whereNull('c.status_label')->orWhereNotIn('c.status_label', TipoCoupon::SETTLED));
        } else {
            $from = $mode === 'today' ? $now->copy()->startOfDay() : $now->copy()->subDays(6)->startOfDay();
            $base->where('c.placed_at', '>=', $from->utc());
        }

        $events = (clone $base)
            ->groupBy('tipo_selections.event_id')
            ->selectRaw('tipo_selections.event_id, MAX(tipo_selections.home_name) as home, MAX(tipo_selections.away_name) as away, '
                .'MAX(tipo_selections.competition_name) as competition, MAX(tipo_selections.country_name) as country, '
                .'MAX(tipo_selections.match_time) as match_time, MAX(tipo_selections.is_live) as live, '
                .'COUNT(DISTINCT c.id) as coupons, SUM(c.stake) as stake')
            ->orderByDesc($sort === 'stake' ? 'stake' : 'coupons')->orderByDesc('stake')
            ->limit(100)->get();

        $picks = $events->isEmpty() ? collect() : (clone $base)
            ->whereIn('tipo_selections.event_id', $events->pluck('event_id'))
            ->groupBy('tipo_selections.event_id', 'tipo_selections.market_name', 'tipo_selections.selection_name', 'tipo_selections.handicap')
            ->selectRaw('tipo_selections.event_id, tipo_selections.market_name as market, tipo_selections.selection_name as pick, '
                .'tipo_selections.handicap as handicap, COUNT(DISTINCT c.id) as coupons, SUM(c.stake) as stake, SUM(c.potential_win) as exposure')
            ->orderByDesc('coupons')->get()->groupBy('event_id');

        return view('panel.tipo_coupons.density', [
            'mode' => $mode,
            'sort' => $sort,
            'events' => $events,
            'picks' => $picks,
            'currency' => $viewer->currency,
        ]);
    }

    /** Riskli Kuponlar: agactaki acik kuponlar; olasi kazanc veya son maca kalan once. */
    public function risky(Request $request): View
    {
        $query = TipoCoupon::query()
            ->whereIn('user_id', User::query()->subtreeOf($request->user())->select('id'))
            ->where(fn (Builder $q) => $q->whereNull('status_label')->orWhereNotIn('status_label', TipoCoupon::SETTLED));
        if ($request->filled('user')) {
            $name = '%'.$request->string('user').'%';
            $query->whereHas('user', fn (Builder $users) => $users->where('username', 'like', $name));
        }
        $minWin = (float) str_replace(',', '.', (string) $request->query('min_win', '0'));
        if ($minWin > 0) {
            $query->where('potential_win', '>=', $minWin);
        }

        $all = (clone $query)->get(['stake', 'potential_win', 'selection_count', 'won_count']);
        $stake = '0.00';
        $exposure = '0.00';
        foreach ($all as $row) {
            $stake = bcadd($stake, (string) $row->stake, 2);
            $exposure = bcadd($exposure, (string) $row->potential_win, 2);
        }
        $currency = $request->user()->currency;
        $sort = $request->query('sort') === 'last_leg' ? 'last_leg' : 'win';

        return view('panel.tipo_coupons.risky', [
            'sort' => $sort,
            'cards' => [
                'open' => $all->count(),
                'stake' => Money::format($stake, $currency),
                'exposure' => Money::format($exposure, $currency),
                'last_leg' => $all->filter(fn ($c) => (int) $c->selection_count - (int) $c->won_count === 1)->count(),
            ],
            'coupons' => (clone $query)->with('user.parent')
                ->orderByRaw($sort === 'last_leg'
                    ? '(CAST(selection_count AS SIGNED) - CAST(won_count AS SIGNED)) asc, potential_win desc'
                    : 'potential_win desc')
                ->limit(200)->get(),
        ]);
    }

    /** Kupon Sorgulama: rakamsa kupon ID, degilse uye adi; agac disi "bulunamadi". */
    public function lookup(Request $request): View|RedirectResponse
    {
        $q = trim((string) $request->query('q', ''));
        if ($q === '') {
            return view('panel.tipo_coupons.lookup');
        }

        $scope = User::query()->subtreeOf($request->user());
        if (ctype_digit($q)) {
            $coupon = TipoCoupon::query()->where('bet_id', (int) $q)->first();
            if ($coupon !== null) {
                abort_unless($scope->whereKey($coupon->user_id)->exists(), 404);

                return redirect()->route('panel.coupons.tipo', $coupon);
            }
        }

        $member = User::query()->where('username', $q)->first();
        if ($member !== null) {
            abort_unless($member->isInSubtreeOf($request->user()), 404);

            return redirect()->route('panel.coupons.index', ['user' => $member->username]);
        }

        return view('panel.tipo_coupons.lookup', ['notFound' => true]);
    }

    private function filter(Request $request, Builder $query): void
    {
        if ($request->filled('q')) {
            $term = trim((string) $request->string('q'));
            $query->where('bet_id', ctype_digit($term) ? (int) $term : 0);
        }
        if ($request->filled('user')) {
            $name = '%'.$request->string('user').'%';
            $query->whereHas('user', fn (Builder $users) => $users->where('username', 'like', $name));
        }
        if ($request->filled('from')) {
            $query->where('placed_at', '>=', $request->date('from')->startOfDay()->utc());
        }
        if ($request->filled('to')) {
            $query->where('placed_at', '<=', $request->date('to')->endOfDay()->utc());
        }
        if (in_array($request->query('type'), ['combo', 'single'], true)) {
            $query->where('type', $request->query('type'));
        }
        $status = (string) $request->query('status');
        if ($status === 'pending') {
            $query->where(fn (Builder $q) => $q->whereNull('status_label')->orWhereNotIn('status_label', TipoCoupon::SETTLED));
        } elseif (isset(TipoCoupon::LABELS[$status])) {
            $query->whereIn('status_label', TipoCoupon::LABELS[$status]);
        }
    }

    /** @return array<string, string> */
    private function cards(Builder $query, Request $request): array
    {
        $totals = ['pending' => '0.00', 'won' => '0.00', 'lost' => '0.00', 'cancelled' => '0.00', 'payout' => '0.00'];
        foreach ((clone $query)->get(['status_label', 'stake', 'payout']) as $row) {
            $status = $row->panelStatus();
            $bucket = in_array($status, ['cancelled', 'refunded', 'void'], true) ? 'cancelled' : $status;
            $totals[$bucket] = bcadd($totals[$bucket], (string) $row->stake, 2);
            if ($status === 'won') {
                $totals['payout'] = bcadd($totals['payout'], (string) $row->payout, 2);
            }
        }
        $placed = bcadd(bcadd($totals['pending'], $totals['won'], 2), $totals['lost'], 2);
        $currency = $request->user()->currency;

        return [
            'placed' => Money::format($placed, $currency),
            'won' => Money::format($totals['payout'], $currency),
            'lost' => Money::format($totals['lost'], $currency),
            'pending' => Money::format($totals['pending'], $currency),
            'balance' => Money::format(bcsub($placed, $totals['payout'], 2), $currency),
            'cancelled' => Money::format($totals['cancelled'], $currency),
        ];
    }
}

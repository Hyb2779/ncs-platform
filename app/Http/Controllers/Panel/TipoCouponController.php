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
        abort_unless($tipoCoupon->user !== null && $tipoCoupon->user->isInSubtreeOf($request->user()), 404);

        $tipoCoupon->ensureDetail($bridge);

        return view('panel.tipo_coupons.show', ['coupon' => $tipoCoupon]);
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
            $coupon = TipoCoupon::query()->where('bet_id', (int) $q)->whereIn('user_id', (clone $scope)->select('id'))->first();
            if ($coupon !== null) {
                return redirect()->route('panel.coupons.tipo', $coupon);
            }
        }

        $member = (clone $scope)->where('username', $q)->first();
        if ($member !== null) {
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

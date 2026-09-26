<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\User;
use App\Services\Sport\CouponCanceller;
use App\Services\Sport\CouponException;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CouponController extends Controller
{
    public function index(Request $request): View
    {
        $query = $this->visible($request);
        $this->filter($request, $query);

        return view('panel.coupons.index', [
            'coupons' => (clone $query)->with(['user', 'selections'])->withCount('selections')->orderByDesc('placed_at')->limit(200)->get(),
            'cards' => $this->cards($query, $request),
        ]);
    }

    public function risky(Request $request): View
    {
        return view('panel.coupons.risky', [
            'coupons' => $this->visible($request)->with('user')->withCount('selections')->where('status', 'pending')->orderByDesc('potential_win')->limit(200)->get(),
        ]);
    }

    public function show(Request $request, Coupon $coupon): View
    {
        $this->authorizeCoupon($request, $coupon);
        $coupon->load(['user', 'canceller', 'selections.fixture.home', 'selections.fixture.away']);

        return view('panel.coupons.show', ['coupon' => $coupon]);
    }

    public function cancel(Request $request, Coupon $coupon, CouponCanceller $canceller): RedirectResponse
    {
        $this->authorizeCoupon($request, $coupon);

        try {
            $canceller->cancel($request->user(), $coupon, (string) $request->input('reason'), $request->ip());
        } catch (CouponException $exception) {
            abort_if($exception->translationKey === 'sport.errors.cancel_forbidden', 403);

            return back()->withErrors(['coupon' => __($exception->translationKey, $exception->replace)]);
        }

        return back()->with('status', __('sport.coupon.cancelled', ['no' => $coupon->coupon_no]));
    }

    private function visible(Request $request): Builder
    {
        $ids = User::query()->subtreeOf($request->user())->pluck('id');

        return Coupon::query()->whereIn('user_id', $ids);
    }

    private function authorizeCoupon(Request $request, Coupon $coupon): void
    {
        $coupon->loadMissing('user');
        abort_unless($coupon->user->isInSubtreeOf($request->user()), 404);
    }

    private function filter(Request $request, Builder $query): void
    {
        if ($request->filled('q')) {
            $term = (string) $request->string('q');
            $query->where(function (Builder $inner) use ($term): void {
                $inner->where('coupon_no', $term)->orWhere('ip', $term);
            });
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
        if (in_array($request->query('status'), ['pending', 'won', 'lost', 'refunded', 'cancelled', 'void'], true)) {
            $query->where('status', $request->query('status'));
        }
    }

    /**
     * @return array<string, string>
     */
    private function cards(Builder $query, Request $request): array
    {
        $rows = (clone $query)->get(['status', 'stake', 'potential_win']);
        $sum = function (array $statuses, string $column) use ($rows): string {
            $total = '0.00';
            foreach ($rows as $row) {
                if (in_array($row->status, $statuses, true)) {
                    $total = bcadd($total, (string) $row->{$column}, 2);
                }
            }

            return $total;
        };
        $placed = $sum(['pending', 'won', 'lost'], 'stake');
        $won = $sum(['won'], 'potential_win');
        $currency = $request->user()->currency;

        return [
            'placed' => Money::format($placed, $currency),
            'won' => Money::format($won, $currency),
            'lost' => Money::format($sum(['lost'], 'stake'), $currency),
            'pending' => Money::format($sum(['pending'], 'stake'), $currency),
            'balance' => Money::format(bcsub($placed, $won, 2), $currency),
            'cancelled' => Money::format($sum(['cancelled', 'refunded'], 'stake'), $currency),
        ];
    }
}

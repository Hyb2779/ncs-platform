<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Services\Sport\CouponCanceller;
use App\Services\Sport\CouponException;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CouponController extends Controller
{
    public function index(Request $request): View
    {
        $status = (string) $request->query('status', 'pending');
        if (! in_array($status, ['pending', 'won', 'lost', 'cancelled'], true)) {
            $status = 'pending';
        }
        $statuses = $status === 'cancelled' ? ['cancelled', 'refunded'] : [$status];

        return view('site.coupons.index', [
            'status' => $status,
            'coupons' => Coupon::query()
                ->where('user_id', $request->user()->id)
                ->whereIn('status', $statuses)
                ->orderByDesc('placed_at')
                ->limit(100)
                ->get(),
        ]);
    }

    public function show(Request $request, Coupon $coupon): View
    {
        abort_unless($coupon->user_id === $request->user()->id, 404);
        $coupon->load(['selections.fixture.home', 'selections.fixture.away', 'selections.fixture.league']);

        return view('site.coupons.show', ['coupon' => $coupon]);
    }

    public function cancel(Request $request, Coupon $coupon, CouponCanceller $canceller): RedirectResponse
    {
        abort_unless($coupon->user_id === $request->user()->id, 404);

        try {
            $canceller->cancel($request->user(), $coupon, (string) $request->input('reason'), $request->ip());
        } catch (CouponException $exception) {
            abort_if($exception->translationKey === 'sport.errors.cancel_forbidden', 403);

            return back()->withErrors(['coupon' => __($exception->translationKey, $exception->replace)]);
        }

        return back()->with('status', __('sport.coupon.cancelled', ['no' => $coupon->coupon_no]));
    }

    public static function money(string $amount): string
    {
        return Money::format($amount, auth()->user()->currency);
    }
}

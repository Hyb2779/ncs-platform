<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\TipoCoupon;
use App\Services\Sport\NcsBridge;
use App\Services\Sport\TipoCouponSync;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/** Oyuncu: Wegas Spor kuponlarim (sadece kendi kuponlari). */
class WegasCouponController extends Controller
{
    private const TABS = ['pending', 'won', 'lost', 'cancelled'];

    public function index(Request $request, TipoCouponSync $sync): View
    {
        $user = $request->user();
        abort_unless(wegas_sport_available($user), 404);

        // Yeni oynanan kupon 5 dk beklemesin: sayfa acilinca bu oyuncu icin anlik cekim (dakikada en fazla bir).
        if (Cache::add('tipo-sync-user:'.$user->id, 1, 60)) {
            $sync->syncUsers([$user->id]);
        }

        $status = in_array($request->query('status'), self::TABS, true) ? (string) $request->query('status') : 'pending';
        $query = TipoCoupon::query()->where('user_id', $user->id);
        if ($status === 'pending') {
            $query->where(fn ($q) => $q->whereNull('status_label')->orWhereNotIn('status_label', TipoCoupon::SETTLED));
        } elseif ($status === 'cancelled') {
            $query->whereIn('status_label', array_merge(TipoCoupon::LABELS['cancelled'], TipoCoupon::LABELS['refunded'], TipoCoupon::LABELS['void']));
        } else {
            $query->whereIn('status_label', TipoCoupon::LABELS[$status]);
        }

        return view('site.wegas-coupons.index', [
            'status' => $status,
            'tabs' => self::TABS,
            'coupons' => $query->orderByDesc('placed_at')->limit(50)->get(),
        ]);
    }

    public function show(Request $request, TipoCoupon $tipoCoupon, NcsBridge $bridge): View
    {
        $user = $request->user();
        abort_unless(wegas_sport_available($user) && (int) $tipoCoupon->user_id === (int) $user->id, 404);
        $tipoCoupon->ensureDetail($bridge);

        return view('site.wegas-coupons.show', ['coupon' => $tipoCoupon]);
    }
}

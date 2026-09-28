<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Kendi spor bülteni ve kupon sistemi kapalıyken oyuncu tarafındaki bu route'lar 404 döner.
 * Açık kalanlar: site.sport.results (Fenix sonuçları) ve site.wegas_sport (Tipo).
 */
class EnsureOwnSportEnabled
{
    public const CLOSED_ROUTES = [
        'site.sport', 'site.sport.show', 'site.sport.live', 'site.sport.live.data', 'site.sport.add', 'site.sport.combo',
        'site.sport.coupon', 'site.sport.coupon.clear', 'site.sport.coupon.place', 'site.sport.coupon.remove',
        'site.coupons', 'site.coupons.live', 'site.coupons.show', 'site.coupons.cancel',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('sport.own_book_enabled') && in_array($request->route()?->getName(), self::CLOSED_ROUTES, true)) {
            abort(404);
        }

        return $next($request);
    }
}

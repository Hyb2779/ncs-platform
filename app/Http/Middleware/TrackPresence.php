<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/** Online kullanicilar: girisli her istekte presence:<id> (5 dk omur), kullanici basina dakikada en fazla 1 yazim. DB'ye yazmaz. */
class TrackPresence
{
    public const TTL = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $user = $request->user();

        if ($user !== null && Cache::add('presence:touch:'.$user->getAuthIdentifier(), 1, 60)) {
            Cache::put('presence:'.$user->getAuthIdentifier(), [
                'at' => now()->getTimestamp(),
                'area' => $request->routeIs('panel.*') ? 'panel' : 'site',
                'ip' => $request->ip(),
                'ua' => mb_substr((string) $request->userAgent(), 0, 255),
            ], self::TTL);
        }

        return $response;
    }
}

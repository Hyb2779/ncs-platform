<?php

namespace App\Http\Middleware;

use App\Support\Domains;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Panel alan adında sadece panel + giriş/çıkış; site alan adında /panel panel alan adına yönlenir.
 */
class SeparateDomains
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Domains::enabled()) {
            return $next($request);
        }

        $query = $request->getQueryString();
        $path = $request->path().($query ? '?'.$query : '');

        if (Domains::isPanelHost($request)) {
            if ($request->is('panel', 'panel/*', 'login', 'logout', 'up')) {
                return $next($request);
            }

            return redirect('/panel');
        }

        if ($request->is('panel', 'panel/*')) {
            return redirect()->away(Domains::panelUrl($path));
        }

        return $next($request);
    }
}

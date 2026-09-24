<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePanelUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->role === UserRole::Uye) {
            return redirect('/');
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** must_change_password işaretli hesap yalnızca şifre ekranını ve çıkışı açabilir. */
class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null || ! $user->must_change_password) {
            return $next($request);
        }

        $member = $user->role === UserRole::Uye;
        $allowed = $member
            ? $request->routeIs('logout', 'site.account', 'site.password')
            : $request->routeIs('logout', 'panel.password.edit', 'panel.password.update');

        if ($allowed) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => __('panel.password_must_change')], 403);
        }

        return redirect()->to($member ? route('site.account') : route('panel.password.edit'));
    }
}

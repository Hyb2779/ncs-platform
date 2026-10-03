<?php

namespace App\Http\Middleware;

use App\Services\HierarchyService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Login only checks status once. This keeps an open session in line with the same rule:
 * if the user or anyone above them is passive/banned, the session ends on the next request.
 */
class EnsureAccountActive
{
    public function __construct(private readonly HierarchyService $hierarchy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $this->passwordChanged($request, $user)) {
            return $this->end($request, null);
        }

        if ($user !== null && $this->hierarchy->loginBlocked($user)) {
            return $this->end($request, __('auth.blocked'));
        }

        return $next($request);
    }

    /**
     * A password reset ends every other open session of that user. The hash is stored per user id,
     * so switching accounts inside one session never looks like a password change.
     */
    /** Kendi sifresini degistiren kisinin MEVCUT oturumu dusmesin (diger oturumlari yine duser). */
    public static function remember(Request $request, $user): void
    {
        $request->session()->regenerate();
        $request->session()->put('account_pw_'.$user->getAuthIdentifier(), hash('sha256', (string) $user->getAuthPassword()));
    }

    private function passwordChanged(Request $request, $user): bool
    {
        $key = 'account_pw_'.$user->getAuthIdentifier();
        $hash = hash('sha256', (string) $user->getAuthPassword());
        $stored = $request->session()->get($key);

        if ($stored === null) {
            $request->session()->put($key, $hash);

            return false;
        }

        return ! hash_equals($stored, $hash);
    }

    private function end(Request $request, ?string $message): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json(['message' => $message ?? ''], 401);
        }

        $redirect = redirect(Route::has('login') ? route('login') : '/');

        return $message === null ? $redirect : $redirect->withErrors(['username' => $message]);
    }
}

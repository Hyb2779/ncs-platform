<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\HierarchyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function create(): View
    {
        return view(\App\Support\Domains::isPanelHost(request()) ? 'auth.panel-login' : 'auth.login');
    }

    public function store(LoginRequest $request, HierarchyService $hierarchy, ActivityLogger $activity): RedirectResponse
    {
        $username = (string) $request->string('username');
        $key = 'login:'.sha1($username.'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()
                ->withInput($request->only('username'))
                ->withErrors(['username' => __('auth.throttle')]);
        }

        $user = User::query()->where('username', $username)->first();

        if ($user === null || ! Auth::attempt(['username' => $username, 'password' => $request->string('password')->toString()], false)) {
            RateLimiter::hit($key, 60);
            $activity->write(null, 'auth.login_failed', $user, ['username' => $username]);

            return back()
                ->withInput($request->only('username'))
                ->withErrors(['username' => __('auth.failed')]);
        }

        // Yanlış kapı (panelde üye / sitede yönetici): şifre hatası gibi davranılır, rol bilgisi sızmaz.
        if (! \App\Support\Domains::gateAllows($request, $user)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            RateLimiter::hit($key, 60);
            $activity->write($user, 'auth.login_failed', $user, ['reason' => 'wrong_gate']);

            return back()
                ->withInput($request->only('username'))
                ->withErrors(['username' => __('auth.failed')]);
        }

        if ($hierarchy->loginBlocked($user)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            RateLimiter::hit($key, 60);
            $activity->write($user, 'auth.login_failed', $user, ['reason' => 'blocked']);

            return back()
                ->withInput($request->only('username'))
                ->withErrors(['username' => __('auth.blocked')]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        $session = Str::random(40);
        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
            'auth_session' => $session,
        ])->save();
        Auth::setUser($user);

        $request->session()->put('auth_session', $session);
        $request->session()->put('locale', $user->language->value);
        $activity->write($user, 'auth.login', $user);

        if ($user->must_change_password) {
            $page = $user->role === \App\Enums\UserRole::Uye ? route('site.account') : route('panel.password.edit');

            return redirect()->to($page);
        }

        if ($user->role === \App\Enums\UserRole::Uye) {
            return redirect()->to($user->homePath());
        }

        return redirect()->intended($user->homePath());
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}

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
use Illuminate\View\View;

class LoginController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
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

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        $request->session()->put('locale', $user->language->value);
        $activity->write($user, 'auth.login', $user);

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

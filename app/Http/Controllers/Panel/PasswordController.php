<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/** Panel kullanicisinin kendi sifresi (sitedeki SiteController::password ile ayni kurallar). Loglanir, sifre loglanmaz. */
class PasswordController extends Controller
{
    public function edit(): View
    {
        return view('panel.password.edit');
    }

    public function update(Request $request, ActivityLogger $logger): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:4', 'confirmed'],
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            return back()->withErrors(['current_password' => __('panel.password_wrong')]);
        }

        if (Hash::check($data['password'], $user->password)) {
            return back()->withErrors(['password' => __('panel.password_same')]);
        }

        $forced = (bool) $user->must_change_password;
        $user->password = $data['password'];
        $user->must_change_password = false;
        $user->save();
        \App\Http\Middleware\EnsureAccountActive::remember($request, $user);
        $logger->write($user, 'user.password_changed', $user, ['ip' => $request->ip()]);

        $redirect = $forced
            ? redirect()->to($user->homePath())
            : redirect()->route('panel.password.edit');

        return $redirect->with('status', __('panel.password_updated'));
    }
}

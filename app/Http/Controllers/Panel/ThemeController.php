<?php

namespace App\Http\Controllers\Panel;

use App\Enums\Theme;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ThemeController extends Controller
{
    public function edit(Request $request): View
    {
        abort_unless($request->user()->role === UserRole::Superadmin, 404);

        return view('panel.theme', ['current' => $request->user()->theme?->value ?? Theme::Classic->value]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()->role === UserRole::Superadmin, 404);
        $data = $request->validate(['theme' => ['required', Rule::in(Theme::values())]]);
        $request->user()->forceFill(['theme' => $data['theme']])->save();

        return back()->with('status', __('site.theme_saved'));
    }
}

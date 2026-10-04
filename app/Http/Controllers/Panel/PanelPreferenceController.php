<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Ust barda gosterilecek bakiye para birimi (cok para birimli hesap). Kalici cerez; sadece hesabin sahip oldugu cuzdanlar. */
class PanelPreferenceController extends Controller
{
    public function currency(Request $request): RedirectResponse
    {
        $value = (string) $request->input('currency');
        $owned = $request->user()->wallets()->toBase()->pluck('currency')->map(fn ($c) => (string) $c)->all();
        abort_unless(in_array($value, $owned, true), 422);

        return back()->withCookie(cookie()->forever('panel_currency', $value));
    }

    /** Ayarlar > Dil secenegi sayfasi. */
    public function languageForm(): \Illuminate\View\View
    {
        return view('panel.preferences.language', ['locales' => \App\Http\Middleware\SetLocale::LOCALES]);
    }

    /** Panel arayuz dili (cerez). Hesabin kayitli dili degismez. */
    public function language(Request $request): RedirectResponse
    {
        $value = (string) $request->input('language');
        abort_unless(in_array($value, \App\Http\Middleware\SetLocale::LOCALES, true), 422);

        return back()->withCookie(cookie()->forever('panel_locale', $value));
    }
}

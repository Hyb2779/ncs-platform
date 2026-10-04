<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public const LOCALES = ['tr', 'en', 'de', 'ar'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null) {
            // Panel kullanicilari (uye disi) arayuz dilini cerezle secer; hesabin kayitli dili (ve uyelere miras) degismez.
            $picked = $request->cookie('panel_locale');
            $locale = $user->role !== \App\Enums\UserRole::Uye && is_string($picked) && in_array($picked, self::LOCALES, true)
                ? $picked
                : $user->language->value;
            app()->setLocale($locale);
            Carbon::setLocale($locale);

        return $next($request);
        }

        $locale = $request->query('lang');

        if (is_string($locale) && in_array($locale, self::LOCALES, true)) {
            $request->session()->put('locale', $locale);
        }

        $locale = $request->session()->get('locale', config('app.locale', 'tr'));

        if (! in_array($locale, self::LOCALES, true)) {
            $locale = 'tr';
        }

        app()->setLocale($locale);
        Carbon::setLocale($locale);

        return $next($request);
    }
}

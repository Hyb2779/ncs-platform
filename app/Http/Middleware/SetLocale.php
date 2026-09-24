<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public const LOCALES = ['tr', 'en', 'de', 'ar'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null) {
            app()->setLocale($user->language->value);

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

        return $next($request);
    }
}

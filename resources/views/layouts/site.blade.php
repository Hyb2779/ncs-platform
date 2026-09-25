<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('heading', __('site.brand'))</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@500;600&family=Cairo:wght@400;600;700&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>:root { --accent: #F5B83D; }</style>
</head>
<body class="min-h-screen bg-[#0E1117] pb-20 font-sans text-[#E8ECF3] md:pb-0" @auth data-balance-url="{{ route('site.balance') }}" @endauth>
    <header class="sticky top-0 z-20 border-b border-[#232B39] bg-[#151A23]">
        <div class="mx-auto flex h-16 max-w-6xl items-center justify-between gap-4 px-4">
            <a class="text-lg font-semibold" href="{{ route('site.home') }}">{{ __('site.brand') }}</a>
            <nav class="hidden items-center gap-4 text-sm text-[#9AA4B5] md:flex">
                <a href="{{ route('site.sport') }}">{{ __('site.sport') }}</a>
                <a href="{{ route('site.live') }}">{{ __('site.live') }}</a>
                <a href="{{ route('site.slots') }}">{{ __('site.slots') }}</a>
                <a href="{{ route('site.live') }}">{{ __('site.live_casino') }}</a>
                <a href="{{ route('site.sport') }}">{{ __('site.results') }}</a>
            </nav>
            <div class="flex items-center gap-3 text-sm">
                @auth
                    <span class="font-numeric text-base" data-balance>{{ $headerBalance }}</span>
                    <a class="inline-flex h-11 items-center rounded-lg bg-[var(--accent)] px-3 font-semibold text-[#0E1117]" href="{{ route('site.account') }}">{{ __('site.account') }}</a>
                @else
                    <a class="inline-flex h-11 items-center rounded-lg bg-[var(--accent)] px-3 font-semibold text-[#0E1117]" href="{{ route('login') }}">{{ __('site.login') }}</a>
                @endauth
            </div>
        </div>
    </header>
    <main class="mx-auto max-w-6xl px-4 py-6">
        @if (session('status'))
            <p class="mb-4 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{{ session('status') }}</p>
        @endif
        @yield('content')
    </main>
    <nav class="fixed inset-x-0 bottom-0 z-20 grid grid-cols-4 border-t border-[#232B39] bg-[#151A23] md:hidden">
        <a class="inline-flex h-14 items-center justify-center text-xs" href="{{ route('site.sport') }}">{{ __('site.sport') }}</a>
        <a class="inline-flex h-14 items-center justify-center text-xs" href="{{ route('site.live') }}">{{ __('site.live') }}</a>
        <a class="inline-flex h-14 items-center justify-center text-xs" href="{{ route('site.slots') }}">{{ __('site.casino') }}</a>
        <a class="inline-flex h-14 items-center justify-center text-xs" href="{{ route('site.account') }}">{{ __('site.account') }}</a>
    </nav>
</body>
</html>

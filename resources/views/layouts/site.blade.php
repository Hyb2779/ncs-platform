<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" data-theme="{{ site_theme() }}" style="-webkit-text-size-adjust: 100%; text-size-adjust: 100%;">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('heading', brand()->name())</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@600;700&family=Cairo:wght@400;600;700&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    
    <link rel="icon" href="/img/brand/favicon.ico?v=4" sizes="48x48">
    <link rel="icon" type="image/png" sizes="32x32" href="/img/brand/favicon-32.png?v=4">
    <link rel="icon" type="image/png" sizes="192x192" href="/img/brand/icon-192.png?v=4">
    <link rel="icon" type="image/png" sizes="512x512" href="/img/brand/icon-512.png?v=4">
    <link rel="apple-touch-icon" href="/img/brand/apple-touch-icon.png?v=4">
    <meta name="theme-color" content="#0B0D22">
</head>
<body class="overflow-x-clip min-h-screen bg-[var(--site-bg)] pb-20 font-sans text-[var(--site-text)] md:pb-0" @auth data-balance-url="{{ route('site.balance') }}" @endauth>
    <header class="sticky top-0 z-20 border-b border-[var(--site-line)] bg-[var(--site-bg-deep)]">
        <div class="site-header-bar mx-auto grid h-14 max-w-[90rem] items-center px-2.5 md:h-16 md:px-6">
            <div class="relative z-10 justify-self-start" x-data="{ open: false }">
                <button class="inline-flex h-9 shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg border border-[var(--site-line-strong)] px-2 text-xs font-bold md:h-10 md:gap-2 md:px-3 md:text-[13px] md:font-semibold" type="button" aria-label="{{ __('site.language') }}" @click="open = !open">
                    <img src="/images/flags/{{ ['tr' => 'tr', 'en' => 'gb', 'de' => 'de', 'ar' => 'sa'][app()->getLocale()] ?? 'tr' }}.svg" alt="" width="20" height="15" style="width:20px;height:15px" class="shrink-0 rounded-sm object-cover">
                    <span class="hidden md:inline">{{ __('panel.languages.'.app()->getLocale()) }}</span>
                </button>
                <div class="absolute start-0 z-30 mt-2 min-w-36 rounded-lg border border-[var(--site-line)] bg-[var(--site-panel)] py-1 text-sm" x-show="open" x-cloak @click.outside="open = false" style="display: none;">
                    @foreach (['tr', 'en', 'de', 'ar'] as $locale)
                        @php($flag = ['tr' => 'tr', 'en' => 'gb', 'de' => 'de', 'ar' => 'sa'][$locale])
                        @auth
                            <form method="POST" action="{{ route('site.locale') }}">
                                @csrf
                                <input type="hidden" name="language" value="{{ $locale }}">
                                <button class="flex w-full items-center gap-2.5 px-3 py-2 text-start {{ app()->getLocale() === $locale ? 'text-white' : 'text-[var(--site-text-2)]' }}" type="submit"><img src="/images/flags/{{ $flag }}.svg" alt="" width="20" height="15" style="width:20px;height:15px" class="shrink-0 rounded-sm object-cover">{{ __('panel.languages.'.$locale) }}</button>
                            </form>
                        @else
                            <a class="flex items-center gap-2.5 px-3 py-2 {{ app()->getLocale() === $locale ? 'text-white' : 'text-[var(--site-text-2)]' }}" href="{{ request()->url().'?'.Arr::query(array_merge(Arr::except(request()->query(), ['user_id']), ['lang' => $locale])) }}"><img src="/images/flags/{{ $flag }}.svg" alt="" width="20" height="15" style="width:20px;height:15px" class="shrink-0 rounded-sm object-cover">{{ __('panel.languages.'.$locale) }}</a>
                        @endauth
                    @endforeach
                </div>
            </div>
            <a class="site-brand z-[1] inline-flex h-10 shrink-0 items-center justify-self-center md:h-11" href="{{ route('site.home') }}" aria-label="{{ brand()->name() }}">@include('brand.logo')</a>
            <div class="site-header-actions relative z-10 flex min-w-0 items-center justify-self-end gap-1 md:gap-3">
                @auth
                    <a class="inline-flex h-9 shrink-0 items-center whitespace-nowrap rounded-lg bg-[var(--accent)] px-2.5 text-sm font-semibold text-[var(--site-on-accent)] md:h-11 md:px-3 md:text-[15px]" href="{{ route('site.account') }}">
                        <span class="font-numeric" data-balance>{{ $headerBalance }}</span>
                    </a>
                @else
                    <a class="inline-flex h-9 shrink-0 items-center whitespace-nowrap rounded-lg bg-[var(--accent)] px-2.5 text-sm font-semibold text-[var(--site-on-accent)] md:h-11 md:px-3 md:text-[15px]" href="{{ route('login') }}" onclick="const d = document.getElementById('login-dialog'); if (d) { event.preventDefault(); d.showModal(); }">{{ __('site.login') }}</a>
                @endauth
            </div>
        </div>
        <nav class="mx-auto hidden max-w-[90rem] items-center justify-center gap-1 px-6 pb-2 text-sm md:flex" aria-label="{{ __('site.sport') }}">
            @php($sportLink = site_sport_link(auth()->user()))
            @foreach ([
                ['route' => 'site.mini', 'match' => 'site.mini', 'label' => __('site.mini')],
                ...($sportLink ? [$sportLink] : []),
                ['route' => 'site.slots', 'match' => 'site.slots', 'label' => __('site.slots')],
                ['route' => 'site.live_casino', 'match' => 'site.live_casino', 'label' => __('site.live_casino')],
                ['route' => 'site.sport.results', 'match' => 'site.sport.results', 'label' => __('site.results')],
            ] as $item)
                <a class="sport-tab inline-flex items-center gap-2 px-4 py-2 font-semibold {{ request()->routeIs(...(array) $item['match']) ? 'sport-tab-on font-bold text-white' : 'text-[var(--site-text-2)]' }}" href="{{ route($item['route']) }}">
                    {{ $item['label'] }}
                    @if (($item['badge'] ?? null) === 'live' && ($liveCount ?? 0) > 0)
                        <span class="rounded bg-[var(--site-live)] px-1.5 py-0.5 text-[11px] font-extrabold text-white">{{ $liveCount }}</span>
                    @endif
                </a>
            @endforeach
        </nav>
    </header>
    @yield('afterHeader')
    <main class="@yield('mainClass', 'mx-auto max-w-6xl px-4 py-6')">
        @if (session('status'))
            <p class="mb-4 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800" role="status">{{ session('status') }}</p>
        @endif
        @if ($errors->any())
            <div class="mb-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800" role="alert">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif
        @yield('content')
    </main>
    @unless (request()->routeIs('site.account', 'site.account.movements', 'site.coupons', 'site.coupons.live', 'site.coupons.show', 'site.wegas_coupons', 'site.wegas_coupons.show'))
        @include('site._footer')
    @endunless
    <nav class="fixed inset-x-0 bottom-0 z-30 flex border-t border-[var(--site-line)] bg-[var(--site-bg-deep)] md:hidden" aria-label="{{ __('site.sport') }}">
        @php($sportLink = site_sport_link(auth()->user()))
        @foreach ([
            ...($sportLink ? [$sportLink] : []),
            ['route' => 'site.mini', 'match' => 'site.mini', 'label' => __('site.mini'), 'path' => 'M13 2 3 14h9l-1 8 10-12h-9l1-8z'],
            ['route' => 'site.slots', 'match' => 'site.slots', 'label' => __('site.slots'), 'path' => 'M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z'],
            ['route' => 'site.live_casino', 'match' => 'site.live_casino', 'label' => __('site.live_casino'), 'path' => 'M15 10l5-3v10l-5-3M3 6h12v12H3z'],
            ['route' => 'site.account', 'match' => ['site.account', 'site.account.movements'], 'label' => __('site.account'), 'path' => 'M12 8a4 4 0 100-8 4 4 0 000 8zM4 21a8 8 0 0116 0'],
        ] as $item)
            <a class="relative flex h-16 min-w-0 flex-1 flex-col items-center justify-center gap-1 px-0.5 text-center text-[11px] leading-tight text-[var(--accent)] {{ request()->routeIs(...(array) $item['match']) ? 'font-extrabold' : 'font-bold' }}" href="{{ route($item['route']) }}">
                <svg class="h-[22px] w-[22px] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="{{ $item['path'] }}"></path></svg>
                <span class="w-full shrink-0 break-words">{{ $item['label'] }}</span>
                @if (($item['badge'] ?? null) === 'live' && ($liveCount ?? 0) > 0)
                    <span class="absolute top-1.5 start-1/2 ms-1.5 inline-flex min-w-[18px] items-center justify-center rounded-full bg-[var(--site-live)] px-1 text-[11px] font-extrabold text-white">{{ $liveCount }}</span>
                @endif
            </a>
            @if ($item['route'] === 'site.sport.live')
                <button class="relative inline-flex h-16 flex-col items-center justify-center gap-1 text-[11px] font-bold text-[var(--accent)]" type="button" aria-label="{{ __('sport.coupon.title') }}" onclick="const sheet = document.getElementById('coupon-sheet'); sheet ? sheet.showModal() : (window.location.href = '{{ route('site.sport') }}')">
                    <svg class="h-[22px] w-[22px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16v4a2 2 0 010 4v4H4v-4a2 2 0 010-4z"></path></svg>
                    {{ __('sport.coupon.title') }}
                    @if (($couponCount ?? 0) > 0)
                        <span class="absolute top-1.5 start-1/2 ms-1.5 inline-flex min-w-[18px] items-center justify-center rounded-full bg-[var(--accent)] px-1 text-[11px] font-extrabold text-[var(--site-on-accent)]">{{ $couponCount }}</span>
                    @endif
                </button>
            @endif
        @endforeach
    </nav>
@guest
    @unless (request()->routeIs('login'))
        <dialog id="login-dialog" class="m-0 mt-auto w-full max-w-none rounded-t-2xl border border-[var(--site-line)] bg-[var(--site-panel)] p-5 pb-[max(1.25rem,env(safe-area-inset-bottom))] text-start text-[var(--site-text)] backdrop:bg-black/70 md:m-auto md:max-w-md md:rounded-2xl md:p-7">
            <div class="mb-5 flex items-center justify-between">
                <h2 class="text-2xl font-extrabold">{{ __('auth.login_title') }}</h2>
                <form method="dialog"><button class="inline-flex h-11 w-11 items-center justify-center rounded-xl text-2xl text-[var(--site-muted)]" type="submit" aria-label="{{ __('auth.close') }}">&times;</button></form>
            </div>
            @include('auth._form', ['prefix' => 'modal'])
        </dialog>
        @if ($errors->has('username'))
            <script>document.getElementById('login-dialog').showModal();</script>
        @endif
    @endunless
@endguest
</body>
</html>

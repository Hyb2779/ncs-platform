<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" data-theme="{{ site_theme() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('heading', brand()->name())</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@600;700&family=Cairo:wght@400;600;700&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    
    <link rel="icon" type="image/png" sizes="32x32" href="/images/brand/favicon-32.png">
    <link rel="icon" type="image/png" sizes="512x512" href="/images/brand/icon-512.png">
    <link rel="apple-touch-icon" href="/images/brand/apple-touch-icon.png">
    <meta name="theme-color" content="#0B0D22">
</head>
<body class="overflow-x-clip min-h-screen bg-[var(--site-bg)] pb-20 font-sans text-[var(--site-text)] md:pb-0" @auth data-balance-url="{{ route('site.balance') }}" @endauth>
    <header class="sticky top-0 z-20 border-b border-[var(--site-line)] bg-[var(--site-bg-deep)]">
        <div class="mx-auto flex h-14 max-w-[90rem] items-center gap-4 px-4 md:h-16 md:gap-8 md:px-6">
            <a class="flex shrink-0 items-center" href="{{ route('site.home') }}" aria-label="{{ brand()->name() }}"><img src="/images/brand/wegas-header.png" alt="{{ brand()->name() }}" width="458" height="128" class="h-8 w-auto md:h-10"></a>
            <nav class="hidden flex-1 items-center gap-1 text-sm md:flex" aria-label="{{ __('site.sport') }}">
                @foreach ([
                    ['route' => 'site.virtual', 'match' => 'site.virtual', 'label' => __('site.virtual')],
                    ...(wegas_sport_available(auth()->user()) ? [['route' => 'site.wegas_sport', 'match' => 'site.wegas_sport', 'label' => brand()->name().' '.__('site.sport')]] : []),
                    ['route' => 'site.slots', 'match' => 'site.slots', 'label' => __('site.slots')],
                    ['route' => 'site.live_casino', 'match' => 'site.live_casino', 'label' => __('site.live_casino')],
                    ['route' => 'site.sport.results', 'match' => 'site.sport.results', 'label' => __('site.results')],
                ] as $item)
                    <a class="sport-tab inline-flex items-center gap-2 px-4 py-2.5 font-semibold {{ request()->routeIs(...(array) $item['match']) ? 'sport-tab-on font-bold text-white' : 'text-[var(--site-text-2)]' }}" href="{{ route($item['route']) }}">
                        {{ $item['label'] }}
                        @if (($item['badge'] ?? null) === 'live' && ($liveCount ?? 0) > 0)
                            <span class="rounded bg-[var(--site-live)] px-1.5 py-0.5 text-[11px] font-extrabold text-white">{{ $liveCount }}</span>
                        @endif
                    </a>
                @endforeach
            </nav>
            <div class="ms-auto flex items-center gap-2 md:gap-3">
                @guest
                    <div class="relative" x-data="{ open: false }">
                        <button class="inline-flex h-9 items-center gap-2 rounded-lg border border-[var(--site-line-strong)] px-2.5 text-xs font-bold md:h-10 md:px-3 md:text-[13px] md:font-semibold" type="button" aria-label="{{ __('site.language') }}" @click="open = !open">
                            <img src="/images/flags/{{ ['tr' => 'tr', 'en' => 'gb', 'de' => 'de', 'ar' => 'sa'][app()->getLocale()] ?? 'tr' }}.svg" alt="" width="20" height="15" style="width:20px;height:15px" class="shrink-0 rounded-sm object-cover">
                            <span class="hidden md:inline">{{ __('panel.languages.'.app()->getLocale()) }}</span>
                        </button>
                        <div class="absolute end-0 z-30 mt-2 min-w-36 rounded-lg border border-[var(--site-line)] bg-[var(--site-panel)] py-1 text-sm" x-show="open" x-cloak @click.outside="open = false" style="display: none;">
                            @foreach (['tr', 'en', 'de', 'ar'] as $locale)
                                <a class="flex items-center gap-2.5 px-3 py-2 {{ app()->getLocale() === $locale ? 'text-white' : 'text-[var(--site-text-2)]' }}" href="{{ request()->fullUrlWithQuery(['lang' => $locale]) }}" lang="{{ $locale }}"><img src="/images/flags/{{ ['tr' => 'tr', 'en' => 'gb', 'de' => 'de', 'ar' => 'sa'][$locale] ?? 'tr' }}.svg" alt="" width="20" height="15" style="width:20px;height:15px" class="shrink-0 rounded-sm object-cover">{{ __('panel.languages.'.$locale) }}</a>
                            @endforeach
                        </div>
                    </div>
                @endguest
                @auth
                    <div class="flex items-center rounded-lg bg-[var(--site-panel-2)] px-3 py-1.5 md:bg-transparent md:px-1">
                        <div class="flex flex-col items-end">
                            <span class="hidden text-[11px] font-semibold tracking-wider text-[var(--site-muted)] md:block">{{ __('site.balance') }}</span>
                            <span class="font-numeric text-[17px] font-bold md:text-xl" data-balance>{{ $headerBalance }}</span>
                        </div>
                    </div>
                    <a class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-[var(--accent)] text-[15px] font-extrabold text-[var(--site-on-accent)]" href="{{ route('site.account') }}" aria-label="{{ __('site.account') }}">{{ mb_strtoupper(mb_substr(auth()->user()->username, 0, 1)) }}</a>
                @else
                    <a class="inline-flex h-11 items-center rounded-lg bg-[var(--accent)] px-3 font-semibold text-[var(--site-on-accent)]" href="{{ route('login') }}" onclick="const d = document.getElementById('login-dialog'); if (d) { event.preventDefault(); d.showModal(); }">{{ __('site.login') }}</a>
                @endauth
            </div>
        </div>
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
    <nav class="fixed inset-x-0 bottom-0 z-30 grid grid-cols-5 border-t border-[var(--site-line)] bg-[var(--site-bg-deep)] md:hidden" aria-label="{{ __('site.sport') }}">
        @foreach ([
            ...(wegas_sport_available(auth()->user()) ? [['route' => 'site.wegas_sport', 'match' => 'site.wegas_sport', 'label' => brand()->name().' '.__('site.sport'), 'path' => 'M12 21a9 9 0 100-18 9 9 0 000 18zM12 7l4 3-1.5 5h-5L8 10z']] : []),
            ['route' => 'site.virtual', 'match' => 'site.virtual', 'label' => __('site.virtual'), 'path' => 'M3 5h18v12H3zM8 21h8M12 17v4'],
            ['route' => 'site.slots', 'match' => 'site.slots', 'label' => __('site.slots'), 'path' => 'M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z'],
            ['route' => 'site.live_casino', 'match' => 'site.live_casino', 'label' => __('site.live_casino'), 'path' => 'M15 10l5-3v10l-5-3M3 6h12v12H3z'],
            ['route' => 'site.account', 'match' => 'site.account', 'label' => __('site.account'), 'path' => 'M12 8a4 4 0 100-8 4 4 0 000 8zM4 21a8 8 0 0116 0'],
        ] as $item)
            <a class="relative inline-flex h-16 flex-col items-center justify-center gap-1 text-[11px] font-bold {{ request()->routeIs(...(array) $item['match']) ? 'text-[var(--accent)]' : 'text-[var(--site-muted)]' }}" href="{{ route($item['route']) }}">
                <svg class="h-[22px] w-[22px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="{{ $item['path'] }}"></path></svg>
                {{ $item['label'] }}
                @if (($item['badge'] ?? null) === 'live' && ($liveCount ?? 0) > 0)
                    <span class="absolute top-1.5 start-1/2 ms-1.5 inline-flex min-w-[18px] items-center justify-center rounded-full bg-[var(--site-live)] px-1 text-[11px] font-extrabold text-white">{{ $liveCount }}</span>
                @endif
            </a>
            @if ($item['route'] === 'site.sport.live')
                <button class="relative inline-flex h-16 flex-col items-center justify-center gap-1 text-[11px] font-semibold text-[var(--site-muted)]" type="button" aria-label="{{ __('sport.coupon.title') }}" onclick="const sheet = document.getElementById('coupon-sheet'); sheet ? sheet.showModal() : (window.location.href = '{{ route('site.sport') }}')">
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

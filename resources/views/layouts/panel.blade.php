<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('heading', __('panel.title')) — {{ brand()->name() }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@500;600&family=Cairo:wght@400;600;700&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --accent: #F5B83D;
            --panel-chart-text: #64748b;
            --panel-chart-grid: #E3E6EB;
            --chart-1: #F5B83D;
            --chart-2: #2563EB;
            --chart-3: #0F766E;
        }
        [data-theme="dark"] {
            --panel-chart-text: #94a3b8;
            --panel-chart-grid: #334155;
            --chart-1: #F5B83D;
            --chart-2: #60A5FA;
            --chart-3: #2DD4BF;
        }
    </style>
    <script>try{if(localStorage.getItem('panel_theme')==='dark'){var d=document.documentElement;d.dataset.theme='dark';d.dataset.panelTheme='dark'}}catch(e){}</script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-[#F3F4F6] font-sans text-slate-900" x-data="{ open: false }">
    <div class="fixed inset-0 z-30 bg-slate-900/40 md:hidden" x-show="open" x-cloak @click="open = false"></div>
    <aside class="fixed inset-y-0 start-0 z-40 flex w-64 flex-col border-e border-[#E3E6EB] bg-white" :class="open ? 'flex' : 'hidden md:flex'" data-nav="drawer">
        <div class="flex items-center justify-between px-4 py-5">
            <p class="text-base font-semibold">{{ brand()->name() }}</p>
            <span class="rounded-lg bg-[#F3F4F6] px-2 py-1 text-[11px] font-semibold tracking-wide text-slate-500">{{ __('panel.badge') }}</span>
        </div>
        @php
            $pcUser = auth()->user();
            $pcRoot = $pcUser->isRootOwner();
            $pcWallets = $headerWallets->keyBy(fn ($w) => $w->currency instanceof \BackedEnum ? $w->currency->value : (string) $w->currency);
            $pcPicked = request()->cookie('panel_currency');
            $pcCurrency = $pcWallets->has($pcPicked) ? $pcPicked : ($pcUser->currency->value ?? $pcWallets->keys()->first());
        @endphp
        <div class="mx-3 rounded-lg bg-[#F3F4F6] px-3 py-3 text-start" data-panel="profile">
            <p class="font-medium">{{ $pcUser->username }}</p>
            <p class="text-sm text-slate-500">{{ __('panel.roles.'.$pcUser->role->value) }}</p>
            @if ($pcWallets->isNotEmpty())
                <div class="mt-2 grid gap-0.5 border-t border-[#E3E6EB] pt-2" data-header="balance">
                    @foreach ($pcWallets as $code => $cardWallet)
                        <div class="flex items-center justify-between text-sm {{ $code === $pcCurrency ? 'font-semibold' : '' }}">
                            <span class="text-slate-500">{{ $pcRoot ? __('wallet.distributed_credit') : __('wallet.balance') }} · {{ $code }}</span>
                            <span class="font-numeric">{{ $pcRoot ? $cardWallet->formattedDistributedBalance() : $cardWallet->formattedBalance() }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
            <div class="mt-2 flex flex-wrap items-center gap-1.5 border-t border-[#E3E6EB] pt-2">
                <form method="POST" action="{{ route('panel.preferences.language') }}" class="flex gap-1">
                    @csrf
                    @foreach (\App\Http\Middleware\SetLocale::LOCALES as $lc)
                        <button class="h-8 rounded-md px-2 text-xs font-semibold uppercase {{ app()->getLocale() === $lc ? 'bg-[#161A22] text-white' : 'bg-white text-slate-600' }}" type="submit" name="language" value="{{ $lc }}" title="{{ __('panel.languages.'.$lc) }}">{{ $lc }}</button>
                    @endforeach
                </form>
                @if ($pcWallets->count() > 1)
                    <form method="POST" action="{{ route('panel.preferences.currency') }}">
                        @csrf
                        <select class="h-8 rounded-md border-0 bg-white py-0 ps-2 pe-7 text-xs font-semibold" name="currency" aria-label="{{ __('panel.display_currency') }}" onchange="this.form.submit()">
                            @foreach ($pcWallets->keys() as $code)
                                <option value="{{ $code }}" @selected($code === $pcCurrency)>{{ $code }}</option>
                            @endforeach
                        </select>
                    </form>
                @endif
            </div>
        </div>
        <nav class="mt-6 grid min-h-0 flex-1 content-start gap-4 overflow-y-auto px-3 pb-6">
            @foreach ($panelSections as $section)
                @php
                    $sectionOpen = $loop->first || collect($section['items'])->contains(fn ($i) => request()->routeIs(...$i['active']));
                @endphp
                <div x-data="{ expanded: {{ $sectionOpen ? 'true' : 'false' }} }">
                    <button class="flex w-full items-center justify-between rounded-lg px-3 py-1 text-[11px] font-semibold tracking-wide text-slate-400" type="button" @click="expanded = !expanded" :aria-expanded="expanded.toString()">
                        <span>{{ $section['label'] }}</span>
                        <svg class="h-3.5 w-3.5 transition-transform" :class="expanded && 'rotate-180'" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.17l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd"/></svg>
                    </button>
                    <div x-show="expanded" @if (! $sectionOpen) style="display: none" @endif>
                    @foreach ($section['items'] as $item)
                        <a
                            class="mt-1 flex h-11 items-center rounded-lg px-3 text-sm {{ request()->routeIs(...$item['active']) ? 'bg-[#161A22] text-white' : 'text-slate-700' }}"
                            href="{{ route($item['route']) }}"
                            @if (request()->routeIs(...$item['active'])) aria-current="page" @endif
                            @click="open = false"
                        >{{ $item['label'] }}</a>
                    @endforeach
                    </div>
                </div>
            @endforeach
        </nav>
    </aside>
    <div class="md:ps-64">
        <header class="flex min-h-14 items-center justify-between gap-2 border-b border-[#E3E6EB] bg-white px-3 md:h-[68px] md:px-6">
            <div class="flex min-w-0 items-center gap-2">
                <button class="inline-flex h-11 items-center rounded-lg border border-[#E3E6EB] bg-white px-3 text-sm md:hidden" type="button" @click="open = !open">{{ __('panel.open_menu') }}</button>
                <h1 class="truncate text-base font-semibold text-start md:text-lg">@yield('heading')</h1>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <button class="inline-flex h-11 w-11 items-center justify-center rounded-lg border border-[#E3E6EB] bg-white" type="button" data-header="theme" aria-label="{{ __('panel.theme_toggle') }}" title="{{ __('panel.theme_toggle') }}" onclick="(function(){var d=document.documentElement,on=d.dataset.theme!=='dark';if(on){d.dataset.theme='dark';d.dataset.panelTheme='dark'}else{delete d.dataset.theme;delete d.dataset.panelTheme}try{localStorage.setItem('panel_theme',on?'dark':'light')}catch(e){}})()">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
                </button>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="inline-flex h-11 items-center rounded-lg border border-[#E3E6EB] bg-white px-3 text-sm" type="submit">{{ __('panel.logout') }}</button>
                </form>
            </div>
        </header>
        <main class="px-4 pt-4 pb-24 md:p-6">
            @if (session('status'))
                <p class="mb-4 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{{ session('status') }}</p>
            @endif
            @if ($errors->any())
                <div class="mb-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">
                    @if (request()->routeIs('panel.sport.limits'))
                        <p>{{ __('sport.panel.error_count', ['count' => $errors->count()]) }}</p>
                    @else
                        @foreach ($errors->all() as $error)
                            <p>{{ $error }}</p>
                        @endforeach
                    @endif
                </div>
            @endif
            @yield('content')
        </main>
    </div>
    <nav class="fixed inset-x-0 bottom-0 z-20 flex border-t border-[#E3E6EB] bg-white pb-[env(safe-area-inset-bottom)] md:hidden" data-nav="bottom">
        @foreach ($panelBottom as $item)
            <a
                class="flex h-16 min-w-0 flex-1 flex-col items-center justify-center px-1 text-center text-[11px] leading-tight {{ request()->routeIs(...$item['active']) ? 'text-[#1A1305]' : 'text-slate-500' }}"
                href="{{ route($item['route']) }}"
                @if (request()->routeIs(...$item['active'])) aria-current="page" @endif
            >
                <span class="mb-1 h-1 w-1 rounded-full {{ request()->routeIs(...$item['active']) ? 'bg-[var(--accent)]' : 'bg-transparent' }}"></span>
                {{ $item['label'] }}
            </a>
        @endforeach
    </nav>
</body>
</html>

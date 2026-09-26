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
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-[#F3F4F6] font-sans text-slate-900" x-data="{ open: false }">
    <div class="fixed inset-0 z-30 bg-slate-900/40 md:hidden" x-show="open" x-cloak @click="open = false"></div>
    <aside class="fixed inset-y-0 start-0 z-40 flex w-64 flex-col border-e border-[#E3E6EB] bg-white" :class="open ? 'flex' : 'hidden md:flex'" data-nav="drawer">
        <div class="flex items-center justify-between px-4 py-5">
            <p class="text-base font-semibold">{{ brand()->name() }}</p>
            <span class="rounded-lg bg-[#F3F4F6] px-2 py-1 text-[11px] font-semibold tracking-wide text-slate-500">{{ __('panel.badge') }}</span>
        </div>
        <div class="mx-3 rounded-lg bg-[#F3F4F6] px-3 py-3 text-start">
            <p class="font-medium">{{ auth()->user()->username }}</p>
            <p class="text-sm text-slate-500">{{ __('panel.roles.'.auth()->user()->role->value) }} · {{ auth()->user()->language->value }} / {{ auth()->user()->currency->value }}</p>
        </div>
        <nav class="mt-6 grid min-h-0 flex-1 content-start gap-4 overflow-y-auto px-3 pb-6">
            @foreach ($panelSections as $section)
                <div>
                    <p class="px-3 text-[11px] font-semibold tracking-wide text-slate-400">{{ $section['label'] }}</p>
                    @foreach ($section['items'] as $item)
                        <a
                            class="mt-1 flex h-11 items-center rounded-lg px-3 text-sm {{ request()->routeIs(...$item['active']) ? 'bg-[#161A22] text-white' : 'text-slate-700' }}"
                            href="{{ route($item['route']) }}"
                            @if (request()->routeIs(...$item['active'])) aria-current="page" @endif
                            @click="open = false"
                        >{{ $item['label'] }}</a>
                    @endforeach
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
                <div class="flex items-center gap-2 text-sm">
                    @if (auth()->user()->role->value === 'owner')
                        <span class="hidden sm:inline">{{ __('wallet.distributed_credit') }}</span>
                        @foreach ($headerWallets as $headerWallet)
                            <span class="font-numeric">{{ $headerWallet->formattedDistributedBalance() }}</span>
                        @endforeach
                    @else
                        @foreach ($headerWallets as $headerWallet)
                            <span class="font-numeric">{{ $headerWallet->formattedBalance() }}</span>
                        @endforeach
                    @endif
                </div>
                <span class="hidden h-6 items-center rounded-md bg-[#F3F4F6] px-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-500 sm:inline-flex" title="{{ __('panel.languages.'.auth()->user()->language->value) }}">{{ auth()->user()->language->value }}</span>
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

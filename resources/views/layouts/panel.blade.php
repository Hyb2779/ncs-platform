<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('heading', __('panel.title')) — {{ __('panel.brand') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@500;600&family=Cairo:wght@400;600;700&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-[#F3F4F6] font-sans text-slate-900" x-data="{ open: false }">
    <div class="fixed inset-0 z-20 bg-slate-900/40 md:hidden" x-show="open" x-cloak @click="open = false"></div>
    <aside class="fixed inset-y-0 start-0 z-30 flex w-64 flex-col border-e border-[#E3E6EB] bg-white" :class="open ? 'flex' : 'hidden md:flex'">
        <div class="flex items-center justify-between px-4 py-5">
            <p class="text-base font-semibold">{{ __('panel.brand') }}</p>
            <span class="rounded-lg bg-[#F3F4F6] px-2 py-1 text-[11px] font-semibold tracking-wide text-slate-500">{{ __('panel.badge') }}</span>
        </div>
        <div class="mx-3 rounded-lg bg-[#F3F4F6] px-3 py-3 text-start">
            <p class="font-medium">{{ auth()->user()->username }}</p>
            <p class="text-sm text-slate-500">{{ __('panel.roles.'.auth()->user()->role->value) }} · {{ auth()->user()->language->value }} / {{ auth()->user()->currency->value }}</p>
        </div>
        <nav class="mt-6 grid gap-4 px-3">
            <div>
                <p class="px-3 text-[11px] font-semibold tracking-wide text-slate-400">{{ __('panel.menu_general') }}</p>
                <a class="mt-1 flex h-10 items-center rounded-lg px-3 text-sm {{ request()->routeIs('panel.dashboard') ? 'bg-[#161A22] text-white' : 'text-slate-700' }}" href="{{ route('panel.dashboard') }}">{{ __('panel.overview') }}</a>
            </div>
            <div>
                <p class="px-3 text-[11px] font-semibold tracking-wide text-slate-400">{{ __('panel.menu_network') }}</p>
                <a class="mt-1 flex h-10 items-center rounded-lg px-3 text-sm {{ request()->routeIs('panel.users.*') ? 'bg-[#161A22] text-white' : 'text-slate-700' }}" href="{{ route('panel.users.index') }}">{{ __('panel.users') }}</a>
                <a class="mt-1 flex h-10 items-center rounded-lg px-3 text-sm {{ request()->routeIs('panel.transactions') ? 'bg-[#161A22] text-white' : 'text-slate-700' }}" href="{{ route('panel.transactions') }}">{{ __('wallet.menu') }}</a>
                @if (auth()->user()->role->value === 'owner')
                    <a class="mt-1 flex h-10 items-center rounded-lg px-3 text-sm {{ request()->routeIs('panel.mint') ? 'bg-[#161A22] text-white' : 'text-slate-700' }}" href="{{ route('panel.mint') }}">{{ __('wallet.mint_menu') }}</a>
                @endif
            </div>
        </nav>
    </aside>
    <div class="md:ps-64">
        <header class="flex h-[68px] items-center justify-between gap-4 border-b border-[#E3E6EB] bg-white px-6">
            <div class="flex items-center gap-3">
                <button class="inline-flex h-10 items-center rounded-lg border border-[#E3E6EB] bg-white px-3 text-sm md:hidden" type="button" @click="open = !open">{{ __('panel.open_menu') }}</button>
                <h1 class="text-lg font-semibold text-start">@yield('heading')</h1>
            </div>
            <div class="flex items-center gap-3">
                <div class="flex items-center gap-2 text-sm">
                    @foreach ($headerWallets as $headerWallet)
                        <span class="font-numeric">{{ $headerWallet->formattedBalance() }}</span>
                    @endforeach
                </div>
                <span class="text-sm text-slate-500">{{ auth()->user()->language->value }}</span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="inline-flex h-10 items-center rounded-lg border border-[#E3E6EB] bg-white px-3 text-sm" type="submit">{{ __('panel.logout') }}</button>
                </form>
            </div>
        </header>
        <main class="p-6">
            @if (session('status'))
                <p class="mb-4 rounded-lg bg-white px-3 py-2 text-sm text-emerald-800">{{ session('status') }}</p>
            @endif
            @yield('content')
        </main>
    </div>
</body>
</html>

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('panel.title') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-100 text-slate-900">
    <div class="md:grid md:grid-cols-[16rem_1fr] min-h-screen">
        <aside class="bg-white border-b md:border-b-0 md:border-e border-slate-200 p-4">
            <p class="text-sm font-semibold">{{ __('panel.title') }}</p>
            <nav class="mt-4 flex md:flex-col gap-2">
                <a class="rounded-md px-3 py-2 text-sm hover:bg-slate-100" href="{{ route('panel.dashboard') }}">{{ __('panel.overview') }}</a>
                <a class="rounded-md px-3 py-2 text-sm hover:bg-slate-100" href="{{ route('panel.users.index') }}">{{ __('panel.users') }}</a>
            </nav>
        </aside>
        <div>
            <header class="flex items-center justify-between gap-4 bg-white border-b border-slate-200 px-4 py-3">
                <div class="text-start">
                    <p class="font-medium">{{ auth()->user()->username }}</p>
                    <p class="text-sm text-slate-500">{{ __('panel.roles.'.auth()->user()->role->value) }} · {{ auth()->user()->language->value }}</p>
                </div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="rounded-md border border-slate-300 px-3 py-1 text-sm" type="submit">{{ __('panel.logout') }}</button>
                </form>
            </header>
            <main class="p-4">
                @if (session('status'))
                    <p class="mb-4 rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{{ session('status') }}</p>
                @endif
                @yield('content')
            </main>
        </div>
    </div>
</body>
</html>

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" data-theme="classic">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('auth.panel_title') }} · {{ brand()->name() }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@600;700&family=Cairo:wght@400;600;700&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="icon" type="image/png" sizes="32x32" href="/images/brand/favicon-32.png">
    <link rel="apple-touch-icon" href="/images/brand/apple-touch-icon.png">
    <meta name="theme-color" content="#0B0D22">
</head>
<body class="min-h-dvh bg-[var(--site-bg)] text-[var(--site-text)] antialiased">
    <main class="flex min-h-dvh items-center justify-center px-4 py-8">
        <div class="w-full max-w-[400px] rounded-2xl border border-[var(--site-line)] bg-[var(--site-panel)] p-6 md:p-8">
            <div class="flex items-center justify-between gap-3">
                <img src="/images/brand/wegas-header.png" alt="{{ brand()->name() }}" width="458" height="128" style="height:36px;width:auto">
                <nav class="flex items-center gap-1" aria-label="{{ __('site.language') }}">
                    @foreach (['tr' => 'tr', 'en' => 'gb', 'de' => 'de', 'ar' => 'sa'] as $locale => $flag)
                        <a class="inline-flex h-8 w-9 items-center justify-center rounded-lg border {{ app()->getLocale() === $locale ? 'border-[var(--accent)]' : 'border-transparent opacity-60 hover:opacity-100' }}" href="{{ route('login', ['lang' => $locale]) }}" lang="{{ $locale }}" title="{{ __('panel.languages.'.$locale) }}">
                            <img src="/images/flags/{{ $flag }}.svg" alt="{{ __('panel.languages.'.$locale) }}" width="20" height="15" style="width:20px;height:15px" class="rounded-sm object-cover">
                        </a>
                    @endforeach
                </nav>
            </div>
            <h1 class="mt-6 text-2xl font-extrabold text-[var(--site-text)]">{{ __('auth.panel_title') }}</h1>
            <p class="mt-1 mb-6 text-sm text-[var(--site-muted)]">{{ __('auth.panel_note') }}</p>
            @include('auth._form', ['prefix' => 'panel', 'hint' => false])
        </div>
    </main>
</body>
</html>

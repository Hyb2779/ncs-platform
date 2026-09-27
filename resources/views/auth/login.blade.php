@extends('layouts.site')

@section('heading', __('auth.login_title'))

@section('mainClass', 'flex min-h-[calc(100dvh-7.5rem)] w-full items-center justify-center px-4 py-6 md:min-h-[calc(100dvh-4rem)]')

@section('content')
    <div class="grid w-full max-w-4xl overflow-hidden rounded-2xl border border-[var(--site-line)] bg-[var(--site-panel)] md:grid-cols-2">
        <div class="hidden flex-col justify-between gap-10 bg-[var(--site-panel-2)] p-10 md:flex">
            <span class="font-numeric text-5xl font-bold text-[var(--site-text)]">{{ brand()->name() }}<span class="text-[var(--accent)]">.</span></span>
            <p class="font-numeric text-4xl font-bold leading-tight text-[var(--site-text)]">{{ __('auth.brand_tagline') }}</p>
            <span class="h-1 w-16 rounded-full bg-[var(--accent)]"></span>
        </div>
        <div class="flex flex-col gap-6 p-6 md:p-10">
            <nav class="flex self-end rounded-xl border border-[var(--site-line)] p-1" aria-label="Language">
                @foreach (['tr', 'en', 'de', 'ar'] as $locale)
                    <a class="inline-flex h-9 items-center rounded-lg px-3 text-sm font-bold {{ app()->getLocale() === $locale ? 'bg-[var(--accent)] text-[var(--site-on-accent)]' : 'text-[var(--site-muted)]' }}" href="{{ route('login', ['lang' => $locale]) }}" lang="{{ $locale }}">{{ strtoupper($locale) }}</a>
                @endforeach
            </nav>
            <span class="font-numeric text-4xl font-bold text-[var(--site-text)] md:hidden">{{ brand()->name() }}<span class="text-[var(--accent)]">.</span></span>
            <h1 class="text-3xl font-extrabold text-[var(--site-text)]">{{ __('auth.login_title') }}</h1>
            @include('auth._form', ['prefix' => 'page'])
        </div>
    </div>
@endsection

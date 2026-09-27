@extends('layouts.site')

@section('heading', __('auth.login_title'))

@section('mainClass', 'flex min-h-[calc(100dvh-7.5rem)] w-full items-center justify-center px-0 py-6 md:min-h-[calc(100dvh-4rem)] md:px-4')

@section('content')
    <div class="w-full md:max-w-sm">
        <div class="mb-4 flex justify-center gap-2 text-sm">
            @foreach (['tr', 'en', 'de', 'ar'] as $locale)
                <a class="inline-flex h-11 items-center rounded-lg border border-[var(--site-line)] px-3" href="{{ route('login', ['lang' => $locale]) }}">{{ $locale }}</a>
            @endforeach
        </div>
        <form class="grid w-full gap-4 rounded-xl bg-[var(--site-panel)] p-6" method="POST" action="{{ route('login.store') }}">
            @csrf
            <h1 class="text-center text-2xl font-semibold">{{ __('auth.login_title') }}</h1>
            <label class="grid gap-1 text-sm">
                <span>{{ __('auth.username') }}</span>
                <input class="h-11 rounded-md border border-[var(--site-line)] bg-[var(--site-bg)] px-3" name="username" value="{{ old('username') }}" autocomplete="username">
            </label>
            <label class="grid gap-1 text-sm">
                <span>{{ __('auth.password') }}</span>
                <input class="h-11 rounded-md border border-[var(--site-line)] bg-[var(--site-bg)] px-3" type="password" name="password" autocomplete="current-password">
            </label>
            @error('username')
                <p class="text-sm text-red-400">{{ $message }}</p>
            @enderror
            <button class="inline-flex h-11 items-center justify-center rounded-lg bg-[var(--accent)] font-semibold text-[var(--site-on-accent)]" type="submit">{{ __('auth.submit') }}</button>
        </form>
    </div>
@endsection

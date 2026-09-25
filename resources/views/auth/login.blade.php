@extends('layouts.site')

@section('heading', __('auth.login_title'))

@section('content')
    <form class="mb-4 flex gap-2 text-sm" method="GET">
        @foreach (['tr', 'en', 'de', 'ar'] as $locale)
            <a class="inline-flex h-11 items-center rounded-lg border border-[#232B39] px-3" href="{{ route('login', ['lang' => $locale]) }}">{{ $locale }}</a>
        @endforeach
    </form>
    <h1 class="text-2xl font-semibold">{{ __('auth.login_title') }}</h1>
    <form class="mt-6 grid max-w-sm gap-4" method="POST" action="{{ route('login.store') }}">
        @csrf
        <label class="grid gap-1 text-sm">
            <span>{{ __('auth.username') }}</span>
            <input class="h-11 rounded-md border border-[#232B39] bg-[#151A23] px-3" name="username" value="{{ old('username') }}" autocomplete="username">
        </label>
        <label class="grid gap-1 text-sm">
            <span>{{ __('auth.password') }}</span>
            <input class="h-11 rounded-md border border-[#232B39] bg-[#151A23] px-3" type="password" name="password" autocomplete="current-password">
        </label>
        @error('username')
            <p class="text-sm text-red-700">{{ $message }}</p>
        @enderror
        <button class="inline-flex h-11 items-center justify-center rounded-lg bg-[var(--accent)] font-semibold text-[#0E1117]" type="submit">{{ __('auth.submit') }}</button>
    </form>
@endsection

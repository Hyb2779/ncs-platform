@extends('layouts.app')

@section('content')
    <h1 class="text-2xl font-semibold text-start">{{ __('auth.login_title') }}</h1>
    <form class="mt-6 grid max-w-sm gap-4" method="POST" action="{{ route('login.store') }}">
        @csrf
        <label class="grid gap-1 text-sm">
            <span>{{ __('auth.username') }}</span>
            <input class="rounded-md border border-slate-300 px-3 py-2" name="username" value="{{ old('username') }}" autocomplete="username">
        </label>
        <label class="grid gap-1 text-sm">
            <span>{{ __('auth.password') }}</span>
            <input class="rounded-md border border-slate-300 px-3 py-2" type="password" name="password" autocomplete="current-password">
        </label>
        @error('username')
            <p class="text-sm text-red-700">{{ $message }}</p>
        @enderror
        <button class="rounded-md bg-slate-900 px-3 py-2 text-sm text-white" type="submit">{{ __('auth.submit') }}</button>
    </form>
@endsection

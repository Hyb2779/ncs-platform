@extends('layouts.site')

@section('heading', __('auth.login_title'))

@section('mainClass', 'flex min-h-[calc(100dvh-7.5rem)] w-full items-center justify-center px-4 py-6 md:min-h-[calc(100dvh-4rem)]')

@section('content')
    <div class="grid w-full max-w-4xl overflow-hidden rounded-2xl border border-[var(--site-line)] bg-[var(--site-panel)] md:grid-cols-2">
        <div class="hidden flex-col justify-between gap-10 bg-[var(--site-panel-2)] p-10 md:flex">
            @include('brand.logo')
            <p class="font-numeric text-4xl font-bold leading-tight text-[var(--site-text)]">{{ __('auth.brand_tagline') }}</p>
            <span class="h-1 w-16 rounded-full bg-[var(--accent)]"></span>
        </div>
        <div class="flex flex-col gap-6 p-6 md:p-10">
            <nav class="flex self-end rounded-xl border border-[var(--site-line)] p-1" aria-label="Language">
                @foreach (['tr', 'en', 'de', 'ar'] as $locale)
                    <a class="inline-flex h-9 items-center gap-1.5 rounded-lg px-2.5 text-xs font-bold {{ app()->getLocale() === $locale ? 'bg-[var(--accent)] text-[var(--site-on-accent)]' : 'text-[var(--site-muted)]' }}" href="{{ route('login', ['lang' => $locale]) }}" lang="{{ $locale }}" title="{{ __('panel.languages.'.$locale) }}"><img src="/images/flags/{{ ['tr' => 'tr', 'en' => 'gb', 'de' => 'de', 'ar' => 'sa'][$locale] ?? 'tr' }}.svg" alt="" width="20" height="15" style="width:20px;height:15px" class="shrink-0 rounded-sm object-cover">{{ strtoupper($locale) }}</a>
                @endforeach
            </nav>
            <span class="self-start md:hidden">@include('brand.logo')</span>
            <h1 class="text-3xl font-extrabold text-[var(--site-text)]">{{ __('auth.login_title') }}</h1>
            @include('auth._form', ['prefix' => 'page'])
        </div>
    </div>
@endsection

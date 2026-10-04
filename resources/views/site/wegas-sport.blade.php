@extends('layouts.site')

@section('heading', brand()->name().' '.__('site.sport'))

@section('mainClass', 'w-full p-0')

@section('content')
    @if ($url)
        <div class="flex h-10 items-center justify-end border-b border-[var(--site-line)] px-4 text-sm">
            <a class="font-bold text-[var(--accent)]" href="{{ route('site.wegas_coupons') }}">{{ __('sport.my_coupons') }}</a>
        </div>
        <iframe class="block h-[calc(100dvh-10rem)] w-full border-0 md:h-[calc(100dvh-6.5rem)]" src="{{ $url }}" title="{{ brand()->name().' '.__('site.sport') }}" allow="fullscreen" referrerpolicy="no-referrer"></iframe>
    @else
        <div class="mx-auto max-w-lg px-4 py-16 text-center">
            <p class="text-lg font-bold text-[var(--site-text)]">{{ __('site.wegas_sport_error', ['brand' => brand()->name()]) }}</p>
            <a class="mt-4 inline-flex h-11 items-center rounded-xl bg-[var(--accent)] px-5 font-extrabold text-[var(--site-on-accent)]" href="{{ route('site.virtual') }}">{{ __('site.virtual') }}</a>
        </div>
    @endif
@endsection

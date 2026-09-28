@extends('layouts.site')

@section('heading', brand()->name().' '.__('site.sport'))

@section('mainClass', 'w-full p-0')

@section('content')
    @if ($url)
        <iframe class="block h-[calc(100dvh-7.5rem)] w-full border-0 md:h-[calc(100dvh-4rem)]" src="{{ $url }}" title="{{ brand()->name().' '.__('site.sport') }}" allow="fullscreen" referrerpolicy="no-referrer"></iframe>
    @else
        <div class="mx-auto max-w-lg px-4 py-16 text-center">
            <p class="text-lg font-bold text-[var(--site-text)]">{{ __('site.wegas_sport_error', ['brand' => brand()->name()]) }}</p>
            <a class="mt-4 inline-flex h-11 items-center rounded-xl bg-[var(--accent)] px-5 font-extrabold text-[var(--site-on-accent)]" href="{{ route('site.virtual') }}">{{ __('site.virtual') }}</a>
        </div>
    @endif
@endsection

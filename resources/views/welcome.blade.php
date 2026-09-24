@extends('layouts.app')

@section('content')
    <p class="text-sm text-slate-500">{{ app()->getLocale() }}</p>
    <h1 class="mt-2 text-3xl font-semibold text-start">{{ __('welcome.heading') }}</h1>
    <p class="mt-4 text-start" x-data>{{ __('welcome.body') }}</p>
    <nav class="mt-8 flex flex-wrap gap-3">
        @foreach (['tr', 'en', 'de', 'ar'] as $locale)
            <a class="rounded-md border border-slate-300 px-3 py-1 text-sm {{ app()->getLocale() === $locale ? 'bg-slate-900 text-white' : '' }}" href="{{ request()->fullUrlWithQuery(['lang' => $locale]) }}">{{ $locale }}</a>
        @endforeach
    </nav>
@endsection

@extends('layouts.panel')

@section('heading', __('sport.panel.lookup'))

@section('content')
    <form class="mb-4 flex flex-wrap gap-2" method="GET" action="{{ route('panel.coupons.lookup') }}">
        <input class="h-11 w-72 max-w-full rounded-md border px-3" name="q" value="{{ request('q') }}" placeholder="{{ __('panel.tipo_lookup_placeholder') }}" autocomplete="off" autocapitalize="off" spellcheck="false" autofocus>
        <button class="inline-flex h-11 items-center rounded-lg border px-3" type="submit">{{ __('sport.panel.search') }}</button>
    </form>
    @if (! empty($notFound))
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">{{ __('panel.tipo_lookup_not_found') }}</div>
    @endif
@endsection

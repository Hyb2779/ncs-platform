@extends('layouts.panel')

@section('heading', __('sport.panel.lookup'))

@section('content')
    <x-panel.filter-bar :dates="false">
        <form class="flex gap-2" method="GET" action="{{ route('panel.coupons.lookup') }}">
            <input class="h-11 rounded-md border px-3" name="id" value="{{ request('id') }}" placeholder="{{ __('sport.panel.lookup_id') }}" inputmode="numeric">
            <button class="inline-flex h-11 items-center rounded-lg border px-3" type="submit">{{ __('sport.panel.lookup_submit') }}</button>
        </form>
    </x-panel.filter-bar>
@endsection

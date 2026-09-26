@extends('layouts.panel')

@section('heading', $heading)

@section('content')
    @include('panel.dashboard._body')
    <x-panel.card class="mt-4" :title="__('panel.ops')">
        <div class="grid gap-3 sm:grid-cols-2">
            <a class="text-sm" href="{{ route('panel.sport.status') }}">{{ __('panel.api_usage') }}: <span class="font-numeric">{{ $ops['used'] }}</span> / <span class="font-numeric">{{ $ops['remaining'] === null ? __('panel.empty_value') : $ops['remaining'] }}</span></a>
            <p class="text-sm">{{ __('panel.last_settle') }}: <span class="font-numeric">{{ $ops['settle'] }}</span></p>
            <p class="text-sm">{{ __('panel.last_live') }}: <span class="font-numeric">{{ $ops['live'] }}</span></p>
            <a class="text-sm" href="{{ route('panel.sport.status') }}">{{ __('panel.manual_pending') }}: <span class="font-numeric">{{ $ops['manual'] }}</span></a>
            <a class="text-sm" href="{{ route('panel.sport.status') }}">{{ __('panel.approaching_void') }}: <span class="font-numeric">{{ $ops['approaching'] }}</span></a>
            <a class="text-sm" href="{{ route('panel.coupons.risky') }}">{{ __('panel.risky_count') }}: <span class="font-numeric">{{ $ops['risky'] }}</span></a>
        </div>
    </x-panel.card>
    <div class="mt-4">
        <x-panel.table :columns="$columns" :rows="$rows" />
    </div>
@endsection

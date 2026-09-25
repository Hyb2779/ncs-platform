@extends('layouts.panel')

@section('heading', __('sport.panel.margins'))

@section('content')
    <form class="mb-4 flex flex-wrap gap-2 rounded-lg bg-white p-3" method="POST" action="{{ route('panel.sport.margins.store') }}">
        @csrf
        <select class="h-11 rounded-md border px-2" name="layer">
            <option value="global">{{ __('sport.panel.layer_global') }}</option>
            <option value="league">{{ __('sport.panel.layer_league') }}</option>
            <option value="fixture">{{ __('sport.panel.layer_fixture') }}</option>
            <option value="market">{{ __('sport.panel.layer_market') }}</option>
        </select>
        <input class="h-11 w-24 rounded-md border px-2" name="league_id" placeholder="{{ __('sport.panel.layer_league') }}">
        <input class="h-11 w-24 rounded-md border px-2" name="fixture_id" placeholder="{{ __('sport.panel.layer_fixture') }}">
        <input class="h-11 w-24 rounded-md border px-2" name="market_code" placeholder="{{ __('sport.markets.1X2') }}">
        <input class="h-11 w-24 rounded-md border px-2" name="margin_percent" placeholder="{{ __('sport.panel.percent') }}">
        <input class="h-11 w-24 rounded-md border px-2" name="max_odd" placeholder="{{ __('sport.panel.max') }}">
        <button class="inline-flex h-11 items-center rounded-lg border px-3" type="submit">{{ __('sport.panel.save') }}</button>
    </form>
    @foreach ($margins as $margin)
        <p class="text-sm">{{ $margin->layer }} {{ $margin->market_code }} {{ $margin->margin }} / {{ $margin->max_odd ?? __('panel.empty_value') }}</p>
    @endforeach
@endsection

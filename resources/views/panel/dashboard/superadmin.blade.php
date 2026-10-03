@extends('layouts.panel')

@section('heading', $heading)

@section('content')
    @include('panel.dashboard._today', ['todayCurrency' => request()->cookie('panel_currency')])
    @include('panel.dashboard._body')
    <div class="mt-4">
        <x-panel.table :columns="$columns" :rows="$rows" />
    </div>
@endsection

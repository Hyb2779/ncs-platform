@extends('layouts.panel')

@section('heading', $heading)

@section('content')
    @include('panel.dashboard._body')
    @if ($rank !== null)
        <div class="mt-4">
            <x-panel.table :columns="$columns" :rows="$rows" />
        </div>
    @endif
@endsection

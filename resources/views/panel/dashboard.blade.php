@extends('layouts.panel')

@section('heading', __('panel.overview'))

@section('content')
    <p class="text-start font-numeric text-lg">{{ __('panel.direct_children', ['count' => $childCount]) }}</p>
@endsection

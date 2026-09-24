@extends('layouts.panel')

@section('content')
    <h1 class="text-2xl font-semibold text-start">{{ __('panel.overview') }}</h1>
    <p class="mt-4 text-start">{{ __('panel.direct_children', ['count' => $childCount]) }}</p>
@endsection

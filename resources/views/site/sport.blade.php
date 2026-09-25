@extends('layouts.site')

@section('heading', __('site.sport'))

@section('content')
    <section class="rounded-lg border border-dashed border-[#232B39] bg-[#151A23] p-8" data-sport-frame>
        <h1 class="text-xl font-semibold">{{ __('site.coming_soon') }}</h1>
        <p class="mt-2 text-[#9AA4B5]">{{ __('site.sport_placeholder') }}</p>
    </section>
@endsection

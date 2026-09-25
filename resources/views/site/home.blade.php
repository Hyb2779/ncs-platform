@extends('layouts.site')

@section('content')
    <div class="grid gap-3 md:grid-cols-2">
        <article class="rounded-lg bg-[#151A23] p-6">{{ __('site.banner_one') }}</article>
        <article class="rounded-lg bg-[#1B2230] p-6">{{ __('site.banner_two') }}</article>
    </div>
    <section class="mt-8">
        <h2 class="mb-3 text-lg font-semibold">{{ __('site.popular_slots') }}</h2>
        <div class="flex gap-3 overflow-x-auto">
            @foreach ($slots as $game)
                @include('site._card', ['game' => $game])
            @endforeach
        </div>
    </section>
    <section class="mt-8">
        <h2 class="mb-3 text-lg font-semibold">{{ __('site.popular_live') }}</h2>
        <div class="flex gap-3 overflow-x-auto">
            @foreach ($live as $game)
                @include('site._card', ['game' => $game])
            @endforeach
        </div>
    </section>
@endsection

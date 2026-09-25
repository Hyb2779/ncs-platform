@extends('layouts.site')

@section('heading', __('site.live'))

@section('mainClass', 'mx-auto w-full max-w-[90rem] px-4 py-4 md:px-6')

@section('afterHeader')
    @include('site.sport._live')
@endsection

@section('content')
    <div class="lg:grid lg:grid-cols-[14.5rem_minmax(0,1fr)_21.25rem] lg:items-start lg:gap-4">
        <aside class="sticky top-20 hidden self-start lg:flex lg:flex-col lg:gap-4">
            @include('site.sport._sports', ['variant' => 'side'])
        </aside>
        <section class="flex min-w-0 flex-col gap-3">
            @include('site.sport._sports', ['variant' => 'chips'])
            @forelse ($fixtures as $group)
                @php $league = $group->first()->league; @endphp
                <p class="text-xs font-bold text-[#9AA4B5] md:hidden">{{ sport_name($league->country) }} · {{ sport_name($league) }}</p>
                <section class="overflow-hidden rounded-xl bg-[#151A23]">
                    <div class="bg-[#1A2029] px-3 py-2">
                        <span class="text-[11px] font-semibold text-[#9AA4B5]">{{ sport_name($league->country) }}</span>
                        <p class="text-sm font-bold text-white">{{ sport_name($league) }}</p>
                    </div>
                    @foreach ($group as $fixture)
                        <a class="grid items-center gap-3 border-b border-[#1D2430] px-3 py-2.5 last:border-b-0 md:grid-cols-[4.5rem_minmax(12rem,1fr)_6rem_minmax(8rem,auto)]" href="{{ route('site.sport.show', $fixture) }}">
                            <span class="font-numeric text-xs font-extrabold text-[var(--accent)]">{{ $fixture->status }}</span>
                            <span class="flex flex-col gap-0.5 text-sm font-semibold">
                                <span class="break-words">{{ sport_name($fixture->home) }}</span>
                                <span class="break-words">{{ sport_name($fixture->away) }}</span>
                            </span>
                            <span class="font-numeric text-base font-bold">{{ $fixture->score_home ?? '0' }} : {{ $fixture->score_away ?? '0' }}</span>
                            <span class="text-[13px] font-semibold text-[#9AA4B5]">{{ __('sport.live_odds_soon') }}</span>
                        </a>
                    @endforeach
                </section>
            @empty
                <p class="text-[#9AA4B5]">{{ __('sport.empty_live') }}</p>
            @endforelse
        </section>
        <aside class="sticky top-20 hidden self-start lg:flex lg:flex-col lg:gap-3">
            @include('site.sport._coupon')
            @include('site.sport._lookup')
        </aside>
    </div>
    @include('site.sport._sheet')
@endsection

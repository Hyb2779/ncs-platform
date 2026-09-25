@extends('layouts.site')

@section('heading', __('site.results'))

@section('mainClass', 'mx-auto w-full max-w-[90rem] px-4 py-4 md:px-6')

@section('afterHeader')
    @include('site.sport._live')
@endsection

@section('content')
    @php $zone = auth()->user()->timezone ?? 'UTC'; @endphp
    <div class="lg:grid lg:grid-cols-[14.5rem_minmax(0,1fr)_21.25rem] lg:items-start lg:gap-4">
        <aside class="sticky top-20 hidden self-start lg:flex lg:flex-col lg:gap-4">
            @include('site.sport._sports', ['variant' => 'side'])
        </aside>
        <section class="flex min-w-0 flex-col gap-3">
            @include('site.sport._sports', ['variant' => 'chips'])
            @forelse ($days as $leagues)
                <h2 class="text-sm font-bold text-white">{{ sport_date($leagues->first()->first()->starts_at->timezone($zone), 'j F Y') }}</h2>
                @foreach ($leagues as $group)
                    @php $league = $group->first()->league; @endphp
                    <p class="text-xs font-bold text-[#9AA4B5] md:hidden">{{ sport_name($league->country) }} · {{ sport_name($league) }}</p>
                    <section class="overflow-hidden rounded-xl bg-[#151A23]">
                        <div class="bg-[#1A2029] px-3 py-2">
                            <span class="text-[11px] font-semibold text-[#9AA4B5]">{{ sport_name($league->country) }}</span>
                            <p class="text-sm font-bold text-white">{{ sport_name($league) }}</p>
                        </div>
                        @foreach ($group as $fixture)
                            <a class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-[#1D2430] px-3 py-2.5 text-sm last:border-b-0" href="{{ route('site.sport.show', $fixture) }}">
                                <span class="text-xs font-bold text-[#9AA4B5]">{{ $fixture->starts_at->timezone($zone)->format('H:i') }} · {{ sport_status($fixture->status) }}</span>
                                <span class="break-words font-semibold">{{ sport_name($fixture->home) }} – {{ sport_name($fixture->away) }}</span>
                                <span class="font-numeric font-bold">{{ $fixture->score_home ?? '0' }}:{{ $fixture->score_away ?? '0' }}</span>
                                @if ($fixture->ht_home !== null && $fixture->ht_away !== null)
                                    <span class="text-xs font-semibold text-[#9AA4B5]">{{ __('sport.half_time') }} {{ $fixture->ht_home }}:{{ $fixture->ht_away }}</span>
                                @endif
                            </a>
                        @endforeach
                    </section>
                @endforeach
            @empty
                <p class="text-[#9AA4B5]">{{ __('sport.empty_results') }}</p>
            @endforelse
        </section>
        <aside class="sticky top-20 hidden self-start lg:flex lg:flex-col lg:gap-3">
            @include('site.sport._coupon')
            @include('site.sport._lookup')
        </aside>
    </div>
    @include('site.sport._sheet')
@endsection

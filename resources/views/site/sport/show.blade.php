@extends('layouts.site')

@section('heading', sport_name($fixture->home).' - '.sport_name($fixture->away))

@section('mainClass', 'mx-auto w-full max-w-[90rem] px-4 py-4 md:px-6')

@section('afterHeader')
    @include('site.sport._live')
@endsection

@section('content')
    @php
        $zone = auth()->user()->timezone ?? 'UTC';
        $kickoff = $fixture->starts_at->timezone($zone);
        $groups = [
            'result' => ['1X2', 'DC'],
            'half' => ['HT1X2'],
            'btts' => ['BTTS'],
            'ou' => ['OU15', 'OU25', 'OU35'],
        ];
    @endphp
    <div class="lg:grid lg:grid-cols-[14.5rem_minmax(0,1fr)_21.25rem] lg:items-start lg:gap-4" x-data="{ tab: '{{ $detailTab }}' }">
        <aside class="sticky top-20 hidden self-start lg:flex lg:flex-col lg:gap-4">
            @include('site.sport._sports', ['variant' => 'side'])
        </aside>
        <section class="flex min-w-0 flex-col gap-3">
            @include('site.sport._sports', ['variant' => 'chips'])
            <a class="text-[13px] text-[#9AA4B5]" href="{{ route('site.sport') }}">{{ __('site.sport') }}</a>
            <div class="rounded-xl bg-[#151A23] p-4">
                <p class="text-[11px] font-semibold text-[#9AA4B5]">{{ sport_name($fixture->league->country) }} · {{ sport_name($fixture->league) }}</p>
                <h1 class="mt-1 flex flex-col gap-0.5 text-xl font-bold text-white">
                    <span class="break-words">{{ sport_name($fixture->home) }}</span>
                    <span class="break-words">{{ sport_name($fixture->away) }}</span>
                </h1>
                <p class="mt-1 font-numeric text-sm text-[#9AA4B5]">{{ sport_date($kickoff, 'j F Y H:i') }} · {{ __('sport.code_prefix', ['code' => $fixture->bulletin_code]) }}</p>
            </div>
            <div class="no-scrollbar flex gap-2 overflow-x-auto">
                @foreach (['result', 'half', 'btts', 'ou'] as $key)
                    <button class="inline-flex h-9 shrink-0 items-center rounded-full px-3.5 text-[13px]" type="button" @click="tab = '{{ $key }}'" :class="tab === '{{ $key }}' ? 'bg-[#E8ECF3] font-bold text-[#0E1117]' : 'border border-[#2A3342] font-semibold text-[#C9D1DD]'">{{ __('sport.filters.'.$key) }}</button>
                @endforeach
            </div>
            @foreach ($groups as $key => $codes)
                <div class="grid gap-3" x-show="tab === '{{ $key }}'" @if ($key !== $detailTab) x-cloak @endif>
                    @foreach ($codes as $code)
                        <section class="rounded-xl bg-[#151A23] p-3">
                            <h2 class="text-sm font-bold text-white">{{ __('sport.markets.'.$code) }}</h2>
                            @php $outcomes = \App\Models\SportMarket::outcomesFor($code); @endphp
                            <div class="mt-2 grid gap-2 {{ count($outcomes) === 3 ? 'max-w-lg grid-cols-3' : 'max-w-md grid-cols-2' }}">
                                @foreach ($outcomes as $outcome)
                                    @include('site.sport._odd', ['fixture' => $fixture, 'market' => $code, 'outcome' => $outcome, 'labeled' => true])
                                @endforeach
                            </div>
                        </section>
                    @endforeach
                </div>
            @endforeach
        </section>
        <aside class="sticky top-20 hidden self-start lg:flex lg:flex-col lg:gap-3">
            @include('site.sport._coupon')
            @include('site.sport._lookup')
        </aside>
    </div>
    @include('site.sport._sheet')
@endsection

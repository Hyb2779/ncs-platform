@extends('layouts.site')

@section('heading', $fixture->home->name.' - '.$fixture->away->name)

@section('content')
    <a class="text-sm text-[#9AA4B5]" href="{{ route('site.sport') }}">{{ __('site.sport') }}</a>
    <h1 class="mt-2 text-xl font-semibold">{{ $fixture->home->name }} - {{ $fixture->away->name }}</h1>
    <p class="font-numeric text-sm text-[#9AA4B5]">{{ $fixture->starts_at->timezone(auth()->user()->timezone ?? 'UTC')->format('d.m.Y H:i') }} · {{ $fixture->bulletin_code }}</p>
    <div class="mt-4 flex flex-wrap gap-2 text-sm">
        @foreach (['all', 'result', 'goals', 'half'] as $tab)
            <a class="inline-flex h-11 items-center rounded-lg border border-[#232B39] px-3" href="{{ route('site.sport.show', ['fixture' => $fixture, 'tab' => $tab]) }}">{{ __('sport.tabs.'.$tab) }}</a>
        @endforeach
    </div>
    @php
        $groups = [
            'result' => ['1X2', 'DC'],
            'goals' => ['OU15', 'OU25', 'OU35', 'BTTS'],
            'half' => ['HT1X2'],
        ];
        $tab = request('tab', 'all');
        $codes = $tab === 'all' ? array_merge(...array_values($groups)) : ($groups[$tab] ?? []);
    @endphp
    <div class="mt-4 grid gap-3">
        @foreach ($fixture->odds->groupBy(fn ($odd) => $odd->market->code) as $code => $odds)
            @continue(! in_array($code, $codes, true))
            <section class="rounded-lg bg-[#151A23] p-3">
                <h2>{{ __($odds->first()->market->name_key) }}</h2>
                <div class="mt-2 flex flex-wrap gap-2">
                    @foreach ($odds as $odd)
                        @include('site.sport._odd', ['fixture' => $fixture, 'market' => $code, 'outcome' => $odd->outcome])
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>
    <div class="mt-4">
        @include('site.sport._coupon')
    </div>
@endsection

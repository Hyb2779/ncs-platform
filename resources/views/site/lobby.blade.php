@extends('layouts.site')

@section('heading', $live ? __('site.live_casino') : __('site.slots'))

@section('content')
<div x-data="{ filters: false }">
    <form class="mb-4 flex gap-2" method="GET">
        <input class="h-11 flex-1 rounded-md border border-[#232B39] bg-[#151A23] px-3" name="q" value="{{ request('q') }}" placeholder="{{ __('site.search') }}">
        <button class="inline-flex h-11 items-center rounded-lg border border-[#232B39] px-3 md:hidden" type="button" @click="filters = true">{{ __('site.filters') }}</button>
    </form>
    <div class="grid gap-4 md:grid-cols-[16rem_1fr]">
        <aside class="hidden rounded-lg bg-[#151A23] p-4 md:block">
            @include('site._filters')
        </aside>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
            @forelse ($games as $game)
                @include('site._card', ['game' => $game])
            @empty
                <p>{{ __('site.empty_games') }}</p>
            @endforelse
        </div>
    </div>
    <div class="fixed inset-0 z-30 bg-[#0E1117]/80 md:hidden" x-show="filters" x-cloak>
        <div class="absolute inset-x-0 bottom-0 rounded-t-lg bg-[#151A23] p-4">
            @include('site._filters')
            <button class="mt-3 inline-flex h-11 items-center" type="button" @click="filters = false">{{ __('site.close') }}</button>
        </div>
    </div>
</div>
@endsection

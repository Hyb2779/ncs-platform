@extends('layouts.panel')

@section('heading', __('sport.panel.status'))

@section('content')
    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <article class="rounded-lg bg-white p-4"><p>{{ __('sport.panel.requests') }}</p><p class="font-numeric">{{ $used }}</p></article>
        <article class="rounded-lg bg-white p-4"><p>{{ __('sport.panel.remaining') }}</p><p class="font-numeric">{{ $remaining ?? __('panel.empty_value') }}</p></article>
        <article class="rounded-lg bg-white p-4"><p>{{ __('sport.panel.settle_check') }}</p><p class="font-numeric">{{ $settleCheck?->last_synced_at?->timezone(auth()->user()->timezone)->format('d.m.Y H:i') ?? __('panel.empty_value') }}</p></article>
        <article class="rounded-lg bg-white p-4"><p>{{ __('sport.panel.pending_settlements') }}</p><p class="font-numeric">{{ $pendingSettlements }}</p></article>
    </div>
    <div class="mb-4 rounded-lg bg-white">
        @foreach ($states as $state)
            <p class="border-b px-3 py-2 text-sm">{{ $state->code }} · {{ $state->last_synced_at?->timezone(auth()->user()->timezone)->format('d.m.Y H:i') ?? __('panel.empty_value') }} · {{ $state->last_error ?: __('panel.empty_value') }}</p>
        @endforeach
    </div>
    <section class="mb-4 rounded-lg bg-white p-4">
        <h2 class="mb-2 font-semibold">{{ __('sport.panel.manual_settle') }}</h2>
        @forelse ($stale as $warning)
            <p class="border-b py-2 text-sm">
                <a class="underline" href="{{ route('panel.sport.fixtures.show', $warning->fixture) }}">{{ sport_name($warning->fixture?->home) }} – {{ sport_name($warning->fixture?->away) }}</a>
            </p>
        @empty
            <p class="text-sm text-slate-500">{{ __('sport.panel.no_warnings') }}</p>
        @endforelse
    </section>
    <section class="mb-4 rounded-lg bg-white p-4">
        <h2 class="mb-2 font-semibold">{{ __('sport.panel.approaching_void') }}</h2>
        @forelse ($approaching as $selection)
            <p class="border-b py-2 text-sm">
                <a class="underline" href="{{ route('panel.sport.fixtures.show', $selection->fixture) }}">{{ sport_name($selection->fixture->home) }} – {{ sport_name($selection->fixture->away) }}</a>
            </p>
        @empty
            <p class="text-sm text-slate-500">{{ __('sport.panel.no_warnings') }}</p>
        @endforelse
    </section>
    <section class="rounded-lg bg-white p-4">
        <h2 class="mb-2 font-semibold">{{ __('sport.panel.overdraft') }}</h2>
        @forelse ($overdrafts as $warning)
            <p class="border-b py-2 text-sm">{{ $warning->user?->username }} · {{ \App\Support\Money::format((string) $warning->amount, $warning->user?->currency ?? auth()->user()->currency) }}</p>
        @empty
            <p class="text-sm text-slate-500">{{ __('sport.panel.no_warnings') }}</p>
        @endforelse
    </section>
@endsection

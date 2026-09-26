@extends('layouts.panel')

@section('heading', __('panel.overview'))

@section('content')
    <p class="text-start font-numeric text-lg">{{ __('panel.direct_children', ['count' => $childCount]) }}</p>
    <section class="mt-6 rounded-lg bg-white p-4">
        <h2 class="mb-2 font-semibold">{{ __('sport.panel.overdraft') }}</h2>
        @forelse ($overdrafts as $warning)
            <p class="border-b py-2 text-sm">{{ $warning->user?->username }} · {{ \App\Support\Money::format((string) $warning->amount, $warning->user?->currency ?? auth()->user()->currency) }}</p>
        @empty
            <p class="text-sm text-slate-500">{{ __('sport.panel.no_warnings') }}</p>
        @endforelse
    </section>
@endsection

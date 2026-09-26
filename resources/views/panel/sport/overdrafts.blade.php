@extends('layouts.panel')

@section('heading', __('sport.panel.overdraft'))

@section('content')
    <div class="rounded-lg bg-white">
        @forelse ($overdrafts as $warning)
            <p class="border-b px-3 py-2 text-sm">{{ $warning->user?->username }} · {{ \App\Support\Money::format((string) $warning->amount, $warning->user?->currency ?? auth()->user()->currency) }}</p>
        @empty
            <p class="px-3 py-2 text-sm text-slate-500">{{ __('sport.panel.no_warnings') }}</p>
        @endforelse
    </div>
@endsection

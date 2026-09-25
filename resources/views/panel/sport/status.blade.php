@extends('layouts.panel')

@section('heading', __('sport.panel.status'))

@section('content')
    <div class="mb-4 flex gap-3">
        <article class="flex-1 rounded-lg bg-white p-4"><p>{{ __('sport.panel.requests') }}</p><p class="font-numeric">{{ $used }}</p></article>
        <article class="flex-1 rounded-lg bg-white p-4"><p>{{ __('sport.panel.remaining') }}</p><p class="font-numeric">{{ $remaining ?? __('panel.empty_value') }}</p></article>
    </div>
    <div class="rounded-lg bg-white">
        @foreach ($states as $state)
            <p class="border-b px-3 py-2 text-sm">{{ $state->code }} · {{ $state->last_synced_at?->timezone(auth()->user()->timezone)->format('d.m.Y H:i') ?? __('panel.empty_value') }} · {{ $state->last_error ?: __('panel.empty_value') }}</p>
        @endforeach
    </div>
@endsection

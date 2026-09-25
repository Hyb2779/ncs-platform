@extends('layouts.panel')

@section('heading', __('site.panel_providers'))

@section('content')
    @foreach ($providers as $provider)
        <article class="mb-3 flex flex-wrap items-center gap-3 rounded-lg bg-white p-4">
            <p class="font-semibold">{{ $provider->name }}</p>
            <form method="POST" action="{{ route('panel.casino.providers.update', $provider) }}">
                @csrf
                @method('PUT')
                <input type="hidden" name="status" value="{{ $provider->status === 'active' ? 'passive' : 'active' }}">
                <button class="inline-flex h-10 items-center rounded-lg border px-3 text-sm" type="submit">{{ $provider->status === 'active' ? __('site.active') : __('site.passive') }}</button>
            </form>
            <form method="POST" action="{{ route('panel.casino.providers.sync', $provider) }}">
                @csrf
                <button class="inline-flex h-10 items-center rounded-lg bg-[#161A22] px-3 text-sm text-white" type="submit">{{ __('site.sync') }}</button>
            </form>
        </article>
    @endforeach
@endsection

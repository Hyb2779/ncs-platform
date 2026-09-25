@extends('layouts.panel')

@section('heading', __('site.panel_games'))

@section('content')
    @foreach ($games as $game)
        <form class="mb-2 flex flex-wrap items-center gap-2 rounded-lg bg-white p-3 text-sm" method="POST" action="{{ route('panel.casino.games.update', $game) }}">
            @csrf
            @method('PUT')
            <span>{{ $game->name }}</span>
            <label><input type="checkbox" name="is_active" value="1" @checked($game->is_active)> {{ __('site.active') }}</label>
            <label><input type="checkbox" name="is_popular" value="1" @checked($game->is_popular)> {{ __('site.popular') }}</label>
            <input class="w-20 rounded-md border px-2 py-1" name="sort_order" value="{{ $game->sort_order }}" aria-label="{{ __('site.order') }}">
            <button class="inline-flex h-10 items-center rounded-lg border px-3" type="submit">{{ __('panel.save') }}</button>
        </form>
    @endforeach
@endsection

@extends('layouts.panel')

@section('heading', __('sport.panel.leagues'))

@section('content')
    <div class="grid gap-3">
        @foreach ($leagues as $league)
            <form class="flex flex-wrap items-center gap-3 rounded-lg bg-white p-3" method="POST" action="{{ route('panel.sport.leagues.update', $league) }}">
                @csrf
                @method('PUT')
                <p class="min-w-48">{{ sport_name($league->country) }} · {{ sport_name($league) }}</p>
                <label class="inline-flex h-11 items-center gap-2"><input type="checkbox" name="is_active" value="1" @checked($league->is_active)> {{ __('sport.panel.active') }}</label>
                <label class="inline-flex h-11 items-center gap-2"><input type="checkbox" name="is_featured" value="1" @checked($league->is_featured)> {{ __('sport.panel.featured') }}</label>
                <input class="h-11 w-20 rounded-md border px-2" name="sort_order" value="{{ $league->sort_order }}">
                <button class="inline-flex h-11 items-center rounded-lg border px-3" type="submit">{{ __('sport.panel.save') }}</button>
            </form>
        @endforeach
    </div>
@endsection

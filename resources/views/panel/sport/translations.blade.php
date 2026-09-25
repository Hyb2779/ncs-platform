@extends('layouts.panel')

@section('heading', __('sport.panel.translations'))

@section('content')
    <p class="mb-4 text-sm text-slate-600">{{ __('sport.panel.pending', ['count' => $pending]) }}</p>
    <form class="mb-4 flex flex-wrap items-center gap-2" method="GET">
        <select class="h-11 rounded-md border px-2" name="type">
            @foreach (['team', 'league', 'country'] as $option)
                <option value="{{ $option }}" @selected($type === $option)>{{ __('sport.panel.types.'.$option) }}</option>
            @endforeach
        </select>
        <select class="h-11 rounded-md border px-2" name="locale">
            @foreach (['tr', 'en', 'de', 'ar'] as $option)
                <option value="{{ $option }}" @selected($locale === $option)>{{ __('panel.languages.'.$option) }}</option>
            @endforeach
        </select>
        <label class="inline-flex h-11 items-center gap-2 text-sm">
            <input type="checkbox" name="missing" value="1" @checked(request()->boolean('missing'))>
            {{ __('sport.panel.missing') }}
        </label>
        <input class="h-11 rounded-md border px-3" name="q" value="{{ request('q') }}" placeholder="{{ __('sport.panel.search') }}">
        <button class="inline-flex h-11 items-center rounded-lg border px-3" type="submit">{{ __('sport.panel.search') }}</button>
    </form>
    <div class="grid gap-3">
        @foreach ($rows as $row)
            <form class="flex flex-wrap items-center gap-3 rounded-lg bg-white p-3" method="POST" action="{{ route('panel.sport.translations.update') }}">
                @csrf
                @method('PUT')
                <input type="hidden" name="entity_type" value="{{ $type }}">
                <input type="hidden" name="entity_id" value="{{ $row->id }}">
                <input type="hidden" name="locale" value="{{ $locale }}">
                <p class="min-w-48">{{ $row->name }}</p>
                <input class="h-11 min-w-48 flex-1 rounded-md border px-3" name="name" value="{{ $names[$row->id] ?? '' }}">
                <button class="inline-flex h-11 items-center rounded-lg border px-3" type="submit">{{ __('sport.panel.save') }}</button>
            </form>
        @endforeach
    </div>
@endsection

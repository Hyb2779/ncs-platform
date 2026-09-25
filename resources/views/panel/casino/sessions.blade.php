@extends('layouts.panel')

@section('heading', __('site.panel_sessions'))

@section('content')
    <form class="mb-4 flex flex-wrap gap-2" method="GET">
        <input class="h-11 rounded-md border px-3" type="date" name="from" value="{{ request('from') }}">
        <input class="h-11 rounded-md border px-3" type="date" name="to" value="{{ request('to') }}">
        <button class="inline-flex h-11 items-center rounded-lg border px-3" type="submit">{{ __('panel.filter') }}</button>
    </form>
    <div class="overflow-x-auto rounded-lg bg-white">
        <table class="w-full text-sm">
            @foreach ($rows as $row)
                <tr class="border-b">
                    <td class="px-3 py-2">{{ $row->opened_at->timezone(auth()->user()->timezone)->format('d.m.Y H:i') }}</td>
                    <td class="px-3 py-2">{{ $row->user->username }}</td>
                    <td class="px-3 py-2">{{ $row->game->name }}</td>
                    <td class="px-3 py-2">{{ $row->game->provider->name }}</td>
                    <td class="px-3 py-2">{{ $row->ip }}</td>
                    <td class="px-3 py-2">{{ $row->device }}</td>
                </tr>
            @endforeach
        </table>
    </div>
@endsection
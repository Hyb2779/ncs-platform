@extends('layouts.panel')

@section('heading', __('site.panel_rounds'))

@section('content')
    <div class="mb-4 flex gap-3">
        <article class="flex-1 rounded-lg bg-white p-4"><p>{{ __('site.total_bet') }}</p><p class="font-numeric">{{ $bet }}</p></article>
        <article class="flex-1 rounded-lg bg-white p-4"><p>{{ __('site.total_win') }}</p><p class="font-numeric">{{ $win }}</p></article>
        <article class="flex-1 rounded-lg bg-white p-4"><p>{{ __('site.net') }}</p><p class="font-numeric">{{ $net }}</p></article>
    </div>
    <form class="mb-4 flex flex-wrap gap-2" method="GET">
        <input class="h-11 rounded-md border px-3" type="date" name="from" value="{{ request('from') }}">
        <input class="h-11 rounded-md border px-3" type="date" name="to" value="{{ request('to') }}">
        <button class="inline-flex h-11 items-center rounded-lg border px-3" type="submit">{{ __('panel.filter') }}</button>
    </form>
    <div class="overflow-x-auto rounded-lg bg-white">
        <table class="w-full text-sm">
            @foreach ($rows as $row)
                <tr class="border-b">
                    <td class="px-3 py-2">{{ $row->created_at->timezone(auth()->user()->timezone)->format('d.m.Y H:i') }}</td>
                    <td class="px-3 py-2">{{ $row->user->username }}</td>
                    <td class="px-3 py-2">{{ $row->game?->name }}</td>
                    <td class="px-3 py-2">{{ $row->status }}</td>
                    <td class="px-3 py-2 text-end font-numeric">{{ $row->balance_before }}</td>
                    <td class="px-3 py-2 text-end font-numeric">{{ $row->amount }}</td>
                    <td class="px-3 py-2 text-end font-numeric">{{ $row->balance_after }}</td>
                </tr>
            @endforeach
        </table>
    </div>
@endsection

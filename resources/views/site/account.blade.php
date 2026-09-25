@extends('layouts.site')

@section('heading', __('site.account'))

@section('content')
    <p class="font-numeric text-2xl" data-balance>{{ $headerBalance }}</p>
    <a class="mt-3 inline-flex h-11 items-center rounded-lg border border-[#232B39] px-3 text-sm font-semibold" href="{{ route('site.coupons') }}">{{ __('sport.my_coupons') }}</a>
    <p class="mt-2 text-sm text-[#9AA4B5]">{{ __('site.language') }}: {{ auth()->user()->language->value }} · {{ __('site.currency') }}: {{ auth()->user()->currency->value }}</p>
    <form class="mt-4 flex flex-wrap gap-2" method="GET">
        <input class="h-11 rounded-md border border-[#232B39] bg-[#151A23] px-3" type="date" name="from" value="{{ request('from') }}">
        <input class="h-11 rounded-md border border-[#232B39] bg-[#151A23] px-3" type="date" name="to" value="{{ request('to') }}">
        <button class="inline-flex h-11 items-center rounded-lg border border-[#232B39] px-3" type="submit">{{ __('panel.filter') }}</button>
    </form>
    <div class="mt-4 grid gap-3 md:hidden">
        @foreach ($rows as $row)
            <article class="rounded-lg bg-[#151A23] p-3">
                <p>{{ $row['when'] }}</p>
                <p>{{ $row['party'] }}</p>
                <p class="font-numeric">{{ $row['before'] }} {{ $row['amount'] }} {{ $row['after'] }}</p>
            </article>
        @endforeach
    </div>
    <div class="mt-4 hidden overflow-x-auto rounded-lg bg-[#151A23] md:block">
        <table class="w-full text-sm">
            @foreach ($rows as $row)
                <tr class="border-b border-[#232B39]">
                    <td class="px-3 py-2">{{ $row['when'] }}</td>
                    <td class="px-3 py-2">{{ $row['party'] }}</td>
                    <td class="px-3 py-2 text-end font-numeric">{{ $row['before'] }}</td>
                    <td class="px-3 py-2 text-end font-numeric">{{ $row['amount'] }}</td>
                    <td class="px-3 py-2 text-end font-numeric">{{ $row['after'] }}</td>
                </tr>
            @endforeach
        </table>
    </div>
    <form class="mt-8 grid max-w-sm gap-3" method="POST" action="{{ route('site.password') }}">
        @csrf
        <h2 class="font-semibold">{{ __('site.password') }}</h2>
        <input class="h-11 rounded-md border border-[#232B39] bg-[#1B2230] px-3" type="password" name="current_password" placeholder="{{ __('site.current_password') }}" required>
        <input class="h-11 rounded-md border border-[#232B39] bg-[#1B2230] px-3" type="password" name="password" placeholder="{{ __('site.new_password') }}" required>
        <input class="h-11 rounded-md border border-[#232B39] bg-[#1B2230] px-3" type="password" name="password_confirmation" placeholder="{{ __('site.confirm_password') }}" required>
        @error('current_password')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
        <button class="inline-flex h-11 items-center justify-center rounded-lg bg-[var(--accent)] font-semibold text-[#0E1117]" type="submit">{{ __('panel.save') }}</button>
    </form>
    <form class="mt-4" method="POST" action="{{ route('logout') }}">
        @csrf
        <button class="inline-flex h-11 items-center" type="submit">{{ __('site.logout') }}</button>
    </form>
@endsection

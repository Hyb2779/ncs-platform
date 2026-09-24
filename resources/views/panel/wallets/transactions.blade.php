@extends('layouts.panel')

@section('heading', __('wallet.ledger_title'))

@section('content')
    <form class="mb-4 flex flex-wrap gap-2" method="GET">
        <select class="rounded-md border border-[#E3E6EB] px-3 py-2 text-sm" name="user">
            <option value="">{{ __('wallet.all_users') }}</option>
            @foreach ($subjects as $subject)
                <option value="{{ $subject->id }}" @selected((string) $selectedUser === (string) $subject->id)>{{ $subject->username }}</option>
            @endforeach
        </select>
        <input class="rounded-md border border-[#E3E6EB] px-3 py-2 text-sm" type="date" name="from" value="{{ request('from') }}" aria-label="{{ __('wallet.date_from') }}">
        <input class="rounded-md border border-[#E3E6EB] px-3 py-2 text-sm" type="date" name="to" value="{{ request('to') }}" aria-label="{{ __('wallet.date_to') }}">
        <select class="rounded-md border border-[#E3E6EB] px-3 py-2 text-sm" name="type">
            <option value="">{{ __('wallet.all_types') }}</option>
            @foreach (['mint', 'transfer_in', 'transfer_out', 'bet', 'win', 'refund', 'bonus', 'adjustment'] as $type)
                <option value="{{ $type }}" @selected(request('type') === $type)>{{ __('wallet.types.'.$type) }}</option>
            @endforeach
        </select>
        <button class="inline-flex h-10 items-center rounded-lg border border-[#E3E6EB] bg-white px-3 text-sm" type="submit">{{ __('panel.filter') }}</button>
    </form>
    <div class="mb-4 grid gap-3 md:grid-cols-3">
        @forelse ($totals as $total)
            <article class="rounded-lg bg-white p-4 text-start">
                <p class="text-sm text-slate-500">{{ __('wallet.added') }}</p>
                <p class="font-numeric text-lg">{{ $total['added'] }}</p>
            </article>
            <article class="rounded-lg bg-white p-4 text-start">
                <p class="text-sm text-slate-500">{{ __('wallet.removed') }}</p>
                <p class="font-numeric text-lg">{{ $total['removed'] }}</p>
            </article>
            <article class="rounded-lg bg-white p-4 text-start">
                <p class="text-sm text-slate-500">{{ __('wallet.difference') }}</p>
                <p class="font-numeric text-lg">{{ $total['difference'] }}</p>
            </article>
        @empty
            <article class="rounded-lg bg-white p-4 text-start">
                <p class="text-sm text-slate-500">{{ __('wallet.difference') }}</p>
                <p class="font-numeric text-lg">{{ __('panel.empty_value') }}</p>
            </article>
        @endforelse
    </div>
    <div class="hidden overflow-x-auto rounded-lg bg-white md:block">
        <table class="w-full text-sm">
            <thead class="bg-[#F3F4F6] text-slate-500">
                <tr>
                    <th class="px-3 py-2 text-start font-medium">{{ __('wallet.when') }}</th>
                    <th class="px-3 py-2 text-start font-medium">{{ __('wallet.actor_user') }}</th>
                    <th class="px-3 py-2 text-end font-medium">{{ __('wallet.balance_before') }}</th>
                    <th class="px-3 py-2 text-end font-medium">{{ __('wallet.amount') }}</th>
                    <th class="px-3 py-2 text-end font-medium">{{ __('wallet.balance_after') }}</th>
                    <th class="px-3 py-2 text-start font-medium">{{ __('wallet.note') }}</th>
                    <th class="px-3 py-2 text-start font-medium">{{ __('wallet.ip') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr class="border-b border-[#E3E6EB]">
                        <td class="px-3 py-2 text-start">{{ $row['when'] }}</td>
                        <td class="px-3 py-2 text-start">{{ $row['actor'] }} › {{ $row['user'] }}</td>
                        <td class="px-3 py-2 text-end font-numeric">{{ $row['before'] }}</td>
                        <td class="px-3 py-2 text-end font-numeric">{{ $row['amount'] }}</td>
                        <td class="px-3 py-2 text-end font-numeric">{{ $row['after'] }}</td>
                        <td class="px-3 py-2 text-start">{{ $row['note'] }}</td>
                        <td class="px-3 py-2 text-start">{{ $row['ip'] }}</td>
                    </tr>
                @empty
                    <tr><td class="px-3 py-4 text-start" colspan="7">{{ __('wallet.empty') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="grid gap-3 md:hidden">
        @forelse ($rows as $row)
            <article class="rounded-md bg-white p-3 text-start">
                <p>{{ $row['when'] }}</p>
                <p>{{ $row['actor'] }} › {{ $row['user'] }}</p>
                <p class="font-numeric">{{ $row['before'] }} → {{ $row['amount'] }} → {{ $row['after'] }}</p>
                <p>{{ $row['note'] }}</p>
                <p>{{ $row['ip'] }}</p>
            </article>
        @empty
            <p>{{ __('wallet.empty') }}</p>
        @endforelse
    </div>
@endsection

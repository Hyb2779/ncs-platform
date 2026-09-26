@extends('layouts.panel')

@section('heading', __('wallet.ledger_title'))

@section('content')
    <x-panel.filter-bar class="mb-4">
        <form class="flex flex-wrap gap-2" method="GET">
            @if (request('from'))
                <input type="hidden" name="from" value="{{ request('from') }}">
            @endif
            @if (request('to'))
                <input type="hidden" name="to" value="{{ request('to') }}">
            @endif
            <select class="h-11 rounded-md border border-[#E3E6EB] px-3 text-sm" name="user">
                <option value="">{{ __('wallet.all_users') }}</option>
                <option value="self" @selected((string) $selectedUser === 'self')>{{ __('wallet.own_account') }}</option>
                @foreach ($subjects as $subject)
                    <option value="{{ $subject->id }}" @selected((string) $selectedUser === (string) $subject->id)>{{ $subject->username }}</option>
                @endforeach
            </select>
            <select class="h-11 rounded-md border border-[#E3E6EB] px-3 text-sm" name="type">
                <option value="">{{ __('wallet.all_types') }}</option>
                @foreach (['mint', 'transfer_in', 'transfer_out', 'bet', 'win', 'refund', 'bonus', 'adjustment'] as $type)
                    <option value="{{ $type }}" @selected(request('type') === $type)>{{ __('wallet.types.'.$type) }}</option>
                @endforeach
            </select>
            <button class="inline-flex h-11 items-center rounded-lg border border-[#E3E6EB] bg-white px-3 text-sm" type="submit">{{ __('panel.filter') }}</button>
        </form>
    </x-panel.filter-bar>
    <div class="mb-4 grid gap-3">
        @foreach ($totals as $total)
            <div class="grid gap-3 sm:grid-cols-3">
                <x-panel.stat :label="$ownAccount ? __('wallet.from_upper') : __('wallet.added')" :value="$total['added']" />
                <x-panel.stat :label="$ownAccount ? __('wallet.to_upper') : __('wallet.removed')" :value="$total['removed']" />
                <x-panel.stat :label="__('wallet.difference')" :value="$total['difference']" />
            </div>
        @endforeach
    </div>
    @php
        $tableRows = [];
        foreach ($rows as $row) {
            $amount = e($row['amount']);
            if ($row['movement']) {
                $amount = e($row['movement']).' '.$amount;
            }
            $tableRows[] = [
                'when' => $row['when'],
                'type' => $row['type'],
                'amount' => new \Illuminate\Support\HtmlString('<span class="'.e($row['tone']).'">'.$amount.'</span>'),
                'parties' => $row['parties'],
                'before' => $row['before'],
                'after' => $row['after'],
                'note' => $row['note'],
                'ip' => $row['ip'],
                '_attrs' => [
                    'data-before' => $row['raw_before'],
                    'data-amount' => $row['raw_amount'],
                    'data-after' => $row['raw_after'],
                ],
            ];
        }
    @endphp
    <x-panel.table
        :empty="__('wallet.empty')"
        :columns="[
            ['key' => 'when', 'label' => __('wallet.when')],
            ['key' => 'type', 'label' => __('wallet.type')],
            ['key' => 'amount', 'label' => __('wallet.amount')],
            ['key' => 'parties', 'label' => __('wallet.parties'), 'priority' => 'detail'],
            ['key' => 'before', 'label' => __('wallet.balance_before'), 'priority' => 'detail'],
            ['key' => 'after', 'label' => __('wallet.balance_after'), 'priority' => 'detail'],
            ['key' => 'note', 'label' => __('wallet.note'), 'priority' => 'detail'],
            ['key' => 'ip', 'label' => __('wallet.ip'), 'priority' => 'detail'],
        ]"
        :rows="$tableRows"
    />
@endsection

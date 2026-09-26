@extends('layouts.panel')

@section('heading', __('panel.users'))

@section('content')
@php
    $badge = function (string $label, string $tone): \Illuminate\Support\HtmlString {
        return new \Illuminate\Support\HtmlString(\Illuminate\Support\Facades\Blade::render(
            '<x-panel.badge :tone="$tone">{{ $label }}</x-panel.badge>',
            ['tone' => $tone, 'label' => $label],
        ));
    };
    $rows = [];
    foreach ($users as $user) {
        $tone = match ($user->status->value) {
            'active' => 'success',
            'banned' => 'danger',
            default => 'warning',
        };
        $actions = '<a href="'.e(route('panel.users.edit', $user)).'">'.e(__('panel.edit')).'</a>';
        if ($user->parent_id === auth()->id()) {
            $actions .= '<button class="ms-2" type="button" @click="open = true; target = '.$user->id.'; key = makeUuid()">'.e(__('wallet.adjust')).'</button>';
        }
        $rows[] = [
            'username' => new \Illuminate\Support\HtmlString('<a href="'.e(route('panel.users.index', ['parent' => $user->id])).'">'.e($user->username).'</a>'),
            'role' => __('panel.roles.'.$user->role->value),
            'status' => $badge(__('panel.statuses.'.$user->status->value), $tone),
            'commission' => $user->formattedCommissionRate(),
            'limit' => $user->formattedChildLimit(),
            'login' => $user->formattedLastLogin(),
            'balance' => $user->wallets->firstWhere('currency', $user->currency)?->formattedBalance(),
            'actions' => new \Illuminate\Support\HtmlString($actions),
        ];
    }
@endphp
<div x-data="{ open: false, target: '', direction: 'add', key: makeUuid() }">
    @if (count($breadcrumb) > 1)
        <nav class="mb-4 flex flex-wrap gap-2 text-sm text-start">
            @foreach ($breadcrumb as $crumb)
                <a class="text-slate-600" href="{{ route('panel.users.index', $crumb->id === auth()->id() ? [] : ['parent' => $crumb->id]) }}">{{ $crumb->username }}</a>
                @if (! $loop->last)
                    <span aria-hidden="true">›</span>
                @endif
            @endforeach
        </nav>
    @endif
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <x-panel.filter-bar class="min-w-0 flex-1">
            <form class="flex flex-wrap gap-2" method="GET">
                @if ($parent->id !== auth()->id())
                    <input type="hidden" name="parent" value="{{ $parent->id }}">
                @endif
                @if (request('from'))
                    <input type="hidden" name="from" value="{{ request('from') }}">
                @endif
                @if (request('to'))
                    <input type="hidden" name="to" value="{{ request('to') }}">
                @endif
                <input class="h-11 rounded-md border border-slate-300 px-3 text-sm" name="q" value="{{ request('q') }}" placeholder="{{ __('panel.search_username') }}">
                <select class="h-11 rounded-md border border-slate-300 px-3 text-sm" name="status">
                    <option value="">{{ __('panel.status_all') }}</option>
                    @foreach (['active', 'passive', 'banned'] as $status)
                        <option value="{{ $status }}" @selected(request('status') === $status)>{{ __('panel.statuses.'.$status) }}</option>
                    @endforeach
                </select>
                <button class="inline-flex h-11 items-center rounded-lg border border-[#E3E6EB] bg-white px-3 text-sm" type="submit">{{ __('panel.filter') }}</button>
            </form>
        </x-panel.filter-bar>
        @if ($canCreate)
            <a class="inline-flex h-11 items-center rounded-lg bg-[#161A22] px-3 text-sm text-white" href="{{ route('panel.users.create', $parent->id === auth()->id() ? [] : ['parent' => $parent->id]) }}">{{ __('panel.create_user') }}</a>
        @endif
    </div>
    <x-panel.table
        :empty="__('panel.empty_users')"
        :columns="[
            ['key' => 'username', 'label' => __('panel.fields.username')],
            ['key' => 'role', 'label' => __('panel.fields.role')],
            ['key' => 'status', 'label' => __('panel.fields.status')],
            ['key' => 'balance', 'label' => __('wallet.balance')],
            ['key' => 'commission', 'label' => __('panel.fields.commission_rate'), 'priority' => 'detail'],
            ['key' => 'limit', 'label' => __('panel.fields.user_limit'), 'priority' => 'detail'],
            ['key' => 'login', 'label' => __('panel.fields.last_login'), 'priority' => 'detail'],
            ['key' => 'actions', 'label' => __('panel.fields.actions')],
        ]"
        :rows="$rows"
    />
    <div class="fixed inset-0 z-40 flex items-center justify-center bg-slate-900/40 p-4" x-show="open" x-cloak>
        <form class="w-full max-w-md rounded-lg bg-white p-4 text-start" method="POST" :action="'{{ url('/panel/users') }}/' + target + '/balance'">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}" :value="key">
            <h2 class="mb-3 text-base font-semibold">{{ __('wallet.adjust') }}</h2>
            <label class="mb-2 block text-sm">{{ __('wallet.direction') }}
                <select class="mt-1 w-full rounded-md border border-[#E3E6EB] px-3 py-2" name="direction" x-model="direction">
                    <option value="add">{{ __('wallet.add') }}</option>
                    <option value="remove">{{ __('wallet.remove') }}</option>
                </select>
            </label>
            <label class="mb-2 block text-sm">{{ __('wallet.amount') }}
                <input class="mt-1 w-full rounded-md border border-[#E3E6EB] px-3 py-2 font-numeric" name="amount" inputmode="decimal" required>
            </label>
            <label class="mb-4 block text-sm">{{ __('wallet.note') }}
                <input class="mt-1 w-full rounded-md border border-[#E3E6EB] px-3 py-2" name="note" maxlength="2000">
            </label>
            <div class="flex justify-end gap-2">
                <button class="inline-flex h-10 items-center rounded-lg border border-[#E3E6EB] bg-white px-3 text-sm" type="button" @click="open = false">{{ __('wallet.cancel') }}</button>
                <button class="inline-flex h-10 items-center rounded-lg bg-[#161A22] px-3 text-sm text-white" type="submit">{{ __('wallet.submit') }}</button>
            </div>
        </form>
    </div>
</div>
@endsection

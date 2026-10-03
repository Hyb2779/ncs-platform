@extends('layouts.panel')

@section('heading', __('panel.menu_balance'))

@section('content')
<div x-data="Object.assign(balanceSheet(@js($me)), {
        type: @js($types[0]),
        q: '',
        accounts: @js($accounts),
        list() {
            const q = this.q.trim().toLowerCase();
            return (this.accounts[this.type] || []).filter((a) => ! q || a.label.includes(q)).slice(0, 50);
        },
    })">
    @if (session('status'))
        <div class="mb-3 max-w-2xl rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="mb-3 max-w-2xl rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">{{ $errors->first() }}</div>
    @endif
    <div class="grid max-w-2xl gap-3">
        <div class="grid gap-1 rounded-lg bg-slate-100 p-1" style="grid-template-columns: repeat({{ count($types) }}, minmax(0, 1fr))">
            @foreach ($types as $type)
                <button class="h-10 rounded-md text-sm font-semibold" type="button" :class="type === @js($type) ? 'bg-white text-slate-900 shadow' : 'text-slate-500'" @click="type = @js($type); q = ''">{{ __('panel.roles.'.$type) }}</button>
            @endforeach
        </div>
        <input class="h-11 rounded-md border border-[#E3E6EB] px-3 text-sm" x-model="q" placeholder="{{ __('panel.balance_search') }}" autocomplete="off" autocapitalize="off" spellcheck="false">
        <div class="divide-y divide-[#E3E6EB] rounded-lg border border-[#E3E6EB] bg-white">
            <template x-for="a in list()" :key="a.id">
                <div class="flex items-center justify-between gap-2 px-3 py-2">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium" x-text="a.name"></p>
                        <p class="truncate text-xs text-slate-500" x-text="a.sub" x-show="a.sub"></p>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <span class="font-numeric text-sm font-semibold" x-text="fmtWith(Number(a.balance), a.symbol)"></span>
                        <button class="h-9 rounded-md bg-emerald-600 px-3 text-sm font-semibold text-white" type="button" @click="openAdjust(a, 'add')">{{ __('wallet.add') }}</button>
                        <button class="h-9 rounded-md bg-rose-600 px-3 text-sm font-semibold text-white" type="button" @click="openAdjust(a, 'remove')">{{ __('wallet.remove') }}</button>
                    </div>
                </div>
            </template>
            <p class="px-3 py-4 text-sm text-slate-500" x-show="list().length === 0">{{ __('panel.balance_empty') }}</p>
        </div>
    </div>
    @include('panel.users._balance_modal')
</div>
@include('panel.users._balance_script')
@endsection

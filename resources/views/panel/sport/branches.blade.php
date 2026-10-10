@extends('layouts.panel')

@section('heading', __('sport.panel.branches'))

@section('content')
<div class="space-y-4">
    @if (session('status'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
    @endif
    <p class="text-sm text-slate-600">{{ __('sport.panel.branches_hint') }}</p>
    @if ($targets->isNotEmpty())
        <form method="GET" action="{{ route('panel.sport.branches') }}" class="flex flex-wrap items-end gap-2 rounded-lg border border-slate-200 bg-white p-3">
            <label class="grid gap-1 text-sm">
                <span class="text-slate-500">{{ __('panel.games_target_label') }}</span>
                <select name="target" class="h-11 min-w-64 rounded-lg border border-slate-300 px-3 text-sm" onchange="this.form.submit()">
                    <option value="">{{ $actorIsRoot ? __('panel.games_target_global') : __('panel.games_target_self') }}</option>
                    @foreach ($targets as $account)
                        <option value="{{ $account->id }}" @selected((int) $target->id === (int) $account->id)>{{ $account->username }} ({{ __('panel.roles.'.$account->role->value) }})</option>
                    @endforeach
                </select>
            </label>
        </form>
    @endif
    <div class="grid gap-2 sm:grid-cols-2">
        @foreach ($sports as $code)
            @include('panel.games._toggle', ['scope' => 'sport', 'value' => $code, 'label' => __('sport.sports.'.$code), 'state' => $state[$code] ?? null])
        @endforeach
    </div>
</div>
@endsection

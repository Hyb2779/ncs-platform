@extends('layouts.panel')

@section('heading', __('sport.panel.risky'))

@section('content')
    <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-panel.stat :label="__('panel.risky_open_count')" :value="(string) $cards['open']" />
        <x-panel.stat :label="__('panel.member_turnover')" :value="$cards['stake']" />
        <x-panel.stat :label="__('panel.risky_exposure')" :value="$cards['exposure']" />
        <x-panel.stat :label="__('panel.risky_last_leg')" :value="(string) $cards['last_leg']" />
    </div>
    <x-panel.filter-bar class="mb-4">
        <form class="flex flex-wrap gap-2" method="GET">
            <input class="h-11 rounded-md border px-3" name="user" value="{{ request('user') }}" placeholder="{{ __('sport.panel.user') }}">
            <input class="h-11 w-44 rounded-md border px-3" name="min_win" value="{{ request('min_win') }}" placeholder="{{ __('panel.risky_min_win') }}" inputmode="decimal">
            <select class="h-11 rounded-md border px-2" name="sort">
                <option value="win" @selected($sort === 'win')>{{ __('panel.risky_sort_win') }}</option>
                <option value="last_leg" @selected($sort === 'last_leg')>{{ __('panel.risky_sort_last') }}</option>
            </select>
            <button class="inline-flex h-11 items-center rounded-lg border px-3" type="submit">{{ __('sport.panel.search') }}</button>
        </form>
    </x-panel.filter-bar>
    @php
        $tz = auth()->user()->timezone;
        $rows = [];
        foreach ($coupons as $coupon) {
            $left = max(0, (int) $coupon->selection_count - (int) $coupon->won_count);
            $rows[] = [
                'no' => new \Illuminate\Support\HtmlString('<a class="underline" href="'.e(route('panel.coupons.tipo', $coupon)).'">'.e($coupon->bet_id).'</a>'),
                'user' => new \Illuminate\Support\HtmlString(
                    '<a class="underline" href="'.e(route('panel.users.show', $coupon->user_id)).'">'.e($coupon->user?->username).'</a>'
                    .($coupon->user?->parent ? '<p class="text-xs text-slate-500">'.e($coupon->user->parent->username).'</p>' : '')
                ),
                'stake' => $coupon->stake,
                'win' => $coupon->potential_win,
                'left' => new \Illuminate\Support\HtmlString('<span class="font-semibold '.($left === 1 ? 'text-rose-600' : '').'">'.$left.' / '.$coupon->selection_count.'</span>'),
                'odds' => $coupon->total_odds,
                'time' => $coupon->placed_at?->timezone($tz)->format('d.m.Y H:i'),
            ];
        }
    @endphp
    <x-panel.table
        :columns="[
            ['key' => 'no', 'label' => __('sport.panel.cols.no')],
            ['key' => 'user', 'label' => __('sport.panel.cols.user')],
            ['key' => 'stake', 'label' => __('sport.panel.cols.stake')],
            ['key' => 'win', 'label' => __('sport.panel.cols.win')],
            ['key' => 'left', 'label' => __('panel.risky_remaining')],
            ['key' => 'odds', 'label' => __('sport.panel.cols.total'), 'priority' => 'detail'],
            ['key' => 'time', 'label' => __('sport.panel.cols.time'), 'priority' => 'detail'],
        ]"
        :rows="$rows"
    />
@endsection

@extends('layouts.panel')

@section('heading', __('sport.panel.fixture_detail'))

@section('content')
    <p class="mb-4 text-sm text-slate-600">{{ sport_name($fixture->home) }} – {{ sport_name($fixture->away) }} · {{ sport_status($fixture->status) }}</p>
    <form id="fixture-score" class="mb-6 max-w-lg pb-24" method="POST" action="{{ route('panel.sport.fixtures.score', $fixture) }}">
        @csrf
        <x-panel.card>
            <x-panel.form-row :label="__('sport.panel.ht_home')">
                <input class="h-11 w-24 rounded-md border px-3" type="number" min="0" name="ht_home" value="{{ old('ht_home', $fixture->ht_home) }}" required>
            </x-panel.form-row>
            <x-panel.form-row :label="__('sport.panel.ht_away')">
                <input class="h-11 w-24 rounded-md border px-3" type="number" min="0" name="ht_away" value="{{ old('ht_away', $fixture->ht_away) }}" required>
            </x-panel.form-row>
            <x-panel.form-row :label="__('sport.panel.ft_home')">
                <input class="h-11 w-24 rounded-md border px-3" type="number" min="0" name="ft_home" value="{{ old('ft_home', $fixture->ft_home) }}" required>
            </x-panel.form-row>
            <x-panel.form-row :label="__('sport.panel.ft_away')">
                <input class="h-11 w-24 rounded-md border px-3" type="number" min="0" name="ft_away" value="{{ old('ft_away', $fixture->ft_away) }}" required>
            </x-panel.form-row>
            <x-panel.form-row :label="__('sport.panel.played_at')">
                <input class="h-11 rounded-md border px-3" type="datetime-local" name="played_at" value="{{ old('played_at', $fixture->played_at?->timezone(auth()->user()->timezone)->format('Y-m-d\TH:i')) }}" required>
            </x-panel.form-row>
        </x-panel.card>
    </form>
    @php
        $rows = [];
        foreach ($coupons as $coupon) {
            $badges = '';
            foreach ($coupon->selections as $selection) {
                $badges .= view('sport._badge', ['status' => $selection->status, 'coupon' => $coupon])->render();
            }
            $rows[] = [
                'no' => $coupon->coupon_no,
                'user' => $coupon->user->username,
                'status' => new \Illuminate\Support\HtmlString(e(__('sport.coupon.statuses.'.$coupon->status)).'<div class="mt-2 flex flex-wrap gap-1">'.$badges.'</div>'),
            ];
        }
    @endphp
    <x-panel.table
        :columns="[
            ['key' => 'no', 'label' => __('sport.panel.cols.no')],
            ['key' => 'user', 'label' => __('sport.panel.cols.user')],
            ['key' => 'status', 'label' => __('sport.panel.cols.status')],
        ]"
        :rows="$rows"
    />
    <x-panel.sticky-actions>
        <button class="inline-flex h-11 items-center rounded-lg bg-[var(--accent)] px-4 text-sm font-semibold text-[#1A1305]" type="submit" form="fixture-score">{{ __('sport.panel.correct_score') }}</button>
    </x-panel.sticky-actions>
@endsection

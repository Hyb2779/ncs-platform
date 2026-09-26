@extends('layouts.panel')

@section('heading', __('sport.panel.fixture_detail'))

@section('content')
    <p class="mb-4 text-sm text-slate-600">{{ sport_name($fixture->home) }} – {{ sport_name($fixture->away) }} · {{ sport_status($fixture->status) }}</p>
    <form class="mb-6 grid max-w-lg gap-3 rounded-lg bg-white p-4" method="POST" action="{{ route('panel.sport.fixtures.score', $fixture) }}">
        @csrf
        <div class="grid grid-cols-2 gap-3">
            <label class="grid gap-1 text-sm">
                <span>{{ __('sport.panel.ht_home') }}</span>
                <input class="h-11 rounded-md border px-3" type="number" min="0" name="ht_home" value="{{ old('ht_home', $fixture->ht_home) }}" required>
            </label>
            <label class="grid gap-1 text-sm">
                <span>{{ __('sport.panel.ht_away') }}</span>
                <input class="h-11 rounded-md border px-3" type="number" min="0" name="ht_away" value="{{ old('ht_away', $fixture->ht_away) }}" required>
            </label>
            <label class="grid gap-1 text-sm">
                <span>{{ __('sport.panel.ft_home') }}</span>
                <input class="h-11 rounded-md border px-3" type="number" min="0" name="ft_home" value="{{ old('ft_home', $fixture->ft_home) }}" required>
            </label>
            <label class="grid gap-1 text-sm">
                <span>{{ __('sport.panel.ft_away') }}</span>
                <input class="h-11 rounded-md border px-3" type="number" min="0" name="ft_away" value="{{ old('ft_away', $fixture->ft_away) }}" required>
            </label>
        </div>
        <button class="inline-flex h-11 items-center justify-center rounded-lg border" type="submit">{{ __('sport.panel.correct_score') }}</button>
    </form>
    <div class="grid gap-2">
        @foreach ($coupons as $coupon)
            <article class="rounded-lg bg-white p-3 text-sm">
                <p class="font-numeric">{{ $coupon->coupon_no }} · {{ $coupon->user->username }} · {{ __('sport.coupon.statuses.'.$coupon->status) }}</p>
                <div class="mt-2 flex flex-wrap gap-1">
                    @foreach ($coupon->selections as $selection)
                        @include('sport._badge', ['status' => $selection->status])
                    @endforeach
                </div>
            </article>
        @endforeach
    </div>
@endsection

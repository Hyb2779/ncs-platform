@extends('layouts.panel')

@section('heading', __('sport.panel.coupons'))

@section('content')
    <div class="mb-4 grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
        @foreach (['placed', 'won', 'lost', 'pending', 'balance', 'cancelled'] as $card)
            <article class="rounded-lg bg-white p-3">
                <p class="text-xs text-slate-500">{{ __('sport.panel.cards.'.$card) }}</p>
                <p class="font-numeric text-lg font-semibold">{{ $cards[$card] }}</p>
            </article>
        @endforeach
    </div>
    <form class="mb-4 flex flex-wrap gap-2" method="GET">
        <input class="h-11 rounded-md border px-3" name="q" value="{{ request('q') }}" placeholder="{{ __('sport.panel.search_coupon') }}">
        <input class="h-11 rounded-md border px-3" name="user" value="{{ request('user') }}" placeholder="{{ __('sport.panel.user') }}">
        <input class="h-11 rounded-md border px-3" type="date" name="from" value="{{ request('from') }}">
        <input class="h-11 rounded-md border px-3" type="date" name="to" value="{{ request('to') }}">
        <select class="h-11 rounded-md border px-2" name="type">
            <option value="">{{ __('sport.panel.type') }}</option>
            @foreach (['combo', 'single'] as $type)
                <option value="{{ $type }}" @selected(request('type') === $type)>{{ __('sport.coupon.'.$type) }}</option>
            @endforeach
        </select>
        <select class="h-11 rounded-md border px-2" name="status">
            <option value="">{{ __('sport.panel.status_filter') }}</option>
            @foreach (['pending', 'won', 'lost', 'void', 'refunded', 'cancelled'] as $status)
                <option value="{{ $status }}" @selected(request('status') === $status)>{{ __('sport.coupon.statuses.'.$status) }}</option>
            @endforeach
        </select>
        <button class="inline-flex h-11 items-center rounded-lg border px-3" type="submit">{{ __('sport.panel.search') }}</button>
    </form>
    <div class="overflow-x-auto rounded-lg bg-white">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b text-start text-slate-500">
                    @foreach (['no', 'user', 'ip', 'time', 'type', 'count', 'total', 'stake', 'win', 'status'] as $head)
                        <th class="px-3 py-2 font-medium">{{ __('sport.panel.cols.'.$head) }}</th>
                    @endforeach
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($coupons as $coupon)
                    <tr class="border-b">
                        <td class="px-3 py-2 font-numeric">{{ $coupon->coupon_no }}</td>
                        <td class="px-3 py-2">{{ $coupon->user->username }}</td>
                        <td class="px-3 py-2">{{ $coupon->ip }}</td>
                        <td class="px-3 py-2">{{ $coupon->placed_at->timezone(auth()->user()->timezone)->format('d.m.Y H:i') }}</td>
                        <td class="px-3 py-2">{{ __('sport.coupon.'.$coupon->type) }}</td>
                        <td class="px-3 py-2">{{ $coupon->selections_count }}</td>
                        <td class="px-3 py-2 font-numeric">{{ $coupon->total_odds }}</td>
                        <td class="px-3 py-2 font-numeric">{{ $coupon->stake }}</td>
                        <td class="px-3 py-2 font-numeric">{{ $coupon->potential_win }}</td>
                        <td class="px-3 py-2">
                            <p>{{ __('sport.coupon.statuses.'.$coupon->status) }}</p>
                            <div class="mt-1 flex flex-wrap gap-1">
                                @foreach ($coupon->selections as $selection)
                                    @include('sport._badge', ['status' => $selection->status, 'coupon' => $coupon])
                                @endforeach
                            </div>
                        </td>
                        <td class="px-3 py-2">
                            <a class="underline" href="{{ route('panel.coupons.show', $coupon) }}">{{ __('sport.panel.detail') }}</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection

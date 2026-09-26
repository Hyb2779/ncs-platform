@extends('layouts.site')

@section('heading', __('sport.my_coupons'))

@section('content')
    <div class="mb-4 flex gap-4 border-b border-[#1D2430]">
        @foreach (['pending', 'won', 'lost', 'void', 'cancelled'] as $tab)
            <a class="py-2.5 text-sm {{ $status === $tab ? 'sport-tab-on font-bold text-white' : 'font-semibold text-[#9AA4B5]' }}" href="{{ route('site.coupons', ['status' => $tab]) }}">{{ __('sport.coupon.statuses.'.$tab) }}</a>
        @endforeach
    </div>
    <div class="grid gap-3" x-data='{
        url: @json(route("site.coupons.live", ["ids" => $coupons->pluck("id")->implode(",")])),
        async refresh() {
            if (! this.url.includes("ids=") || this.url.endsWith("ids=")) {
                return;
            }
            const response = await fetch(this.url, { headers: { "Accept": "application/json" } });
            if (! response.ok) {
                return;
            }
            const payload = await response.json();
            for (const row of payload.selections || []) {
                const node = this.$root.querySelector("[data-live-selection=\"" + row.id + "\"]");
                if (! node) {
                    continue;
                }
                const text = node.querySelector("[data-live-text]");
                const pulse = node.querySelector("[data-live-pulse]");
                if (text) {
                    text.textContent = row.text;
                }
                if (pulse) {
                    pulse.classList.toggle("hidden", ! row.live);
                }
            }
        },
        init() {
            setInterval(() => this.refresh(), 60000);
        },
    }'>
        @forelse ($coupons as $coupon)
            <a class="grid gap-2 rounded-xl bg-[#151A23] p-4 md:grid-cols-4" href="{{ route('site.coupons.show', $coupon) }}">
                <span class="font-numeric text-lg font-bold">{{ $coupon->coupon_no }}</span>
                <span class="text-sm text-[#9AA4B5]">{{ sport_date($coupon->placed_at->timezone(auth()->user()->timezone), 'j F Y H:i') }} · {{ __('sport.coupon.'.$coupon->type) }}</span>
                <span class="font-numeric text-sm">{{ \App\Support\Money::format((string) $coupon->stake, auth()->user()->currency) }} · {{ $coupon->total_odds }}</span>
                <span class="text-sm font-semibold text-[#3DD68C]">{{ \App\Support\Money::format((string) $coupon->potential_win, auth()->user()->currency) }} · {{ __('sport.coupon.statuses.'.$coupon->status) }}</span>
                <span class="grid gap-2 md:col-span-4">
                    @foreach ($coupon->selections as $selection)
                        <span class="grid gap-1">
                            <span>@include('sport._badge', ['status' => $selection->status, 'coupon' => $coupon])</span>
                            @include('sport._selection_meta', ['selection' => $selection, 'coupon' => $coupon])
                        </span>
                    @endforeach
                </span>
            </a>
        @empty
            <p class="text-[#9AA4B5]">{{ __('sport.coupon.empty') }}</p>
        @endforelse
    </div>
@endsection

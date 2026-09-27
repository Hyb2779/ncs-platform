@extends('layouts.site')

@section('heading', __('sport.my_coupons'))

@section('content')
    <div class="mb-4 flex gap-4 border-b border-[var(--site-line)]">
        @foreach (['pending', 'won', 'lost', 'void', 'cancelled'] as $tab)
            <a class="py-2.5 text-sm {{ $status === $tab ? 'sport-tab-on font-bold text-white' : 'font-semibold text-[var(--site-muted)]' }}" href="{{ route('site.coupons', ['status' => $tab]) }}">{{ __('sport.coupon.statuses.'.$tab) }}</a>
        @endforeach
    </div>
    <div class="grid gap-3 md:grid-cols-2 md:items-start" x-data='{
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
            <a class="block rounded-2xl border border-[var(--site-line)] bg-[var(--site-panel)] p-4" href="{{ route('site.coupons.show', $coupon) }}">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="font-numeric text-lg font-bold text-[var(--site-text)]">{{ $coupon->coupon_no }}</p>
                        <p class="text-xs text-[var(--site-muted)]">{{ sport_date($coupon->placed_at->timezone(auth()->user()->timezone), 'j F Y H:i') }} · {{ __('sport.coupon.'.$coupon->type) }}</p>
                    </div>
                    @include('sport._coupon_status')
                </div>
                <div class="mt-3">@include('sport._coupon_metrics')</div>
                <div class="mt-1 divide-y divide-[var(--site-line)]">
                    @foreach ($coupon->selections as $selection)
                        @include('sport._selection_row')
                    @endforeach
                </div>
            </a>
        @empty
            <p class="text-[var(--site-muted)]">{{ __('sport.coupon.empty') }}</p>
        @endforelse
    </div>
@endsection

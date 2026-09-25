<div class="rounded-xl bg-[#151A23] p-3.5">
    <form class="flex gap-2" method="GET" action="{{ url()->current() }}">
        @foreach (request()->except('coupon_no') as $name => $value)
            @if (! is_array($value))
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endif
        @endforeach
        <label class="flex min-w-0 flex-1">
            <span class="sr-only">{{ __('sport.coupon.lookup_label') }}</span>
            <input class="h-10 w-full rounded-lg border border-[#2A3342] bg-[#0E1117] px-3 text-[13px] text-[#E8ECF3]" type="text" name="coupon_no" value="{{ request('coupon_no') }}" placeholder="{{ __('sport.coupon.lookup') }}" autocomplete="off">
        </label>
        <button class="h-10 shrink-0 rounded-lg border border-[#2A3342] px-3.5 text-[13px] font-bold text-[#E8ECF3]" type="submit">{{ __('sport.coupon.fetch') }}</button>
    </form>
    @if (request()->filled('coupon_no'))
        <p class="mt-2 text-[13px] text-[#9AA4B5]">{{ __('sport.coupon.lookup_soon') }}</p>
    @endif
</div>

@if (count($coupon['rows']) > 0)
    <button class="fixed inset-x-3 bottom-[76px] z-20 flex h-14 items-center justify-between rounded-xl bg-[var(--accent)] px-4 text-[var(--site-on-accent)] shadow-[0_8px_24px_rgba(0,0,0,0.45)] lg:hidden" type="button" onclick="document.getElementById('coupon-sheet').showModal()">
        <span class="flex items-center gap-2.5 text-sm font-extrabold">
            <span class="inline-flex h-[26px] w-[26px] items-center justify-center rounded-full bg-[var(--site-on-accent)] text-[13px] text-[var(--accent)]">{{ count($coupon['rows']) }}</span>
            {{ __('sport.coupon.open') }}
        </span>
        <span class="font-numeric text-[19px] font-bold">{{ __('sport.coupon.bar_odds', ['odds' => $coupon['total']]) }}</span>
    </button>
@endif
<dialog id="coupon-sheet" class="m-0 w-full max-w-none bg-transparent p-0 backdrop:bg-[rgba(5,7,10,0.65)] lg:hidden">
    <form class="flex min-h-screen flex-col justify-end" method="dialog">
        <button class="min-h-24 flex-1" type="submit" aria-label="{{ __('site.close') }}"></button>
    </form>
    <div class="fixed inset-x-0 bottom-0 max-h-[85vh] overflow-y-auto rounded-t-[18px] bg-[var(--site-panel)] px-4 pb-20 pt-2 text-[var(--site-text)]">
        <div class="mx-auto mb-2 h-1 w-10 rounded-full bg-[var(--site-line-strong)]"></div>
        <div class="mb-3 flex items-center justify-between">
            <p class="text-base font-extrabold">{{ __('sport.coupon.title') }} <span class="font-semibold text-[var(--site-muted)]">· {{ __('sport.coupon.selections', ['count' => count($coupon['rows'])]) }}</span></p>
            @if (count($coupon['rows']) > 0)
                <form method="POST" action="{{ route('site.sport.coupon.clear') }}">
                    @csrf
                    <button class="text-[13px] font-bold text-[var(--site-muted)] underline" type="submit">{{ __('sport.coupon.clear') }}</button>
                </form>
            @endif
        </div>
        @include('site.sport._coupon')
        <div class="mt-3">
            @include('site.sport._lookup')
        </div>
    </div>
</dialog>

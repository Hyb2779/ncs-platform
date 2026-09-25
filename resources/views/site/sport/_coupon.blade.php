<section class="rounded-lg bg-[#151A23] p-4">
    <h2 class="text-lg font-semibold">{{ __('sport.coupon.title') }}</h2>
    @if (in_array('suspended', $coupon['warnings'], true))
        <p class="mt-2 text-sm text-red-400">{{ __('sport.coupon.suspended') }}</p>
    @endif
    @if (in_array('changed', $coupon['warnings'], true))
        <p class="mt-2 text-sm text-amber-300">{{ __('sport.coupon.changed') }}</p>
    @endif
    @forelse ($coupon['rows'] as $row)
        <p class="mt-3 text-sm">{{ $row['odd']->fixture->home->name }} - {{ $row['odd']->fixture->away->name }}</p>
        <p class="text-sm text-[#9AA4B5]">{{ __($row['odd']->market->name_key) }} · {{ __('sport.outcomes.'.$row['odd']->outcome) }} <span class="font-numeric">{{ $row['shown'] }}</span></p>
    @empty
        <p class="mt-3 text-sm text-[#9AA4B5]">{{ __('sport.coupon.empty') }}</p>
    @endforelse
    <form class="mt-4 grid gap-3" method="POST" action="{{ route('site.sport.coupon') }}">
        @csrf
        <div class="flex gap-2 text-sm">
            <label class="inline-flex h-11 items-center gap-2"><input type="radio" name="mode" value="combo" @checked($coupon['mode'] !== 'single')> {{ __('sport.coupon.combo') }}</label>
            <label class="inline-flex h-11 items-center gap-2"><input type="radio" name="mode" value="single" @checked($coupon['mode'] === 'single')> {{ __('sport.coupon.single') }}</label>
        </div>
        <label class="grid gap-1 text-sm">
            <span>{{ __('sport.coupon.stake') }}</span>
            <input class="h-11 rounded-md border border-[#232B39] bg-[#0E1117] px-3 font-numeric" name="stake" value="{{ $coupon['stake'] }}">
        </label>
        <div class="flex flex-wrap gap-2">
            @foreach ($coupon['quick'] as $amount)
                <button class="inline-flex h-11 items-center rounded-lg border border-[#232B39] px-3 font-numeric" type="submit" name="stake" value="{{ $amount }}">{{ $amount }}</button>
            @endforeach
        </div>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="accept" value="1" @checked($coupon['accept'])> {{ __('sport.coupon.accept') }}</label>
        <p class="text-sm">{{ __('sport.coupon.total') }} <span class="font-numeric">{{ $coupon['total'] }}</span></p>
        <p class="text-sm">{{ __('sport.coupon.payout') }} <span class="font-numeric">{{ $coupon['payout'] }}</span></p>
        <button class="inline-flex h-11 items-center justify-center rounded-lg border border-[#232B39]" type="submit">{{ __('panel.save') }}</button>
    </form>
    <form method="POST" action="{{ route('site.sport.coupon.clear') }}">
        @csrf
        <button class="mt-2 inline-flex h-11 items-center" type="submit">{{ __('sport.coupon.clear') }}</button>
    </form>
    <button class="mt-3 inline-flex h-11 w-full items-center justify-center gap-2 rounded-lg bg-[#1B2230] text-[#9AA4B5]" type="button" disabled>
        {{ __('sport.coupon.confirm') }}
        <span class="rounded bg-[#232B39] px-2 py-1 text-xs">{{ __('sport.coupon.soon') }}</span>
    </button>
</section>

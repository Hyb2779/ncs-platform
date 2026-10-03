    <div class="fixed inset-0 z-40 flex items-end md:items-center md:justify-center md:p-4" x-show="open" x-cloak @keydown.escape.window="open = false">
        <div class="absolute inset-0 bg-slate-900/40" @click="open = false"></div>
        <form class="relative grid w-full gap-3 rounded-t-xl bg-white p-4 pb-[max(1rem,env(safe-area-inset-bottom))] text-start md:max-w-md md:rounded-lg md:pb-4" method="POST" :action="'{{ url('/panel/users') }}/' + target + '/balance'" @submit="if (blocked()) $event.preventDefault()">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}" :value="key">
            <input type="hidden" name="direction" :value="direction">
            <input type="hidden" name="currency" :value="balances ? currency : ''">
            <input type="hidden" name="amount" :value="amount() ? amount().toFixed(2) : ''">
            <div class="flex items-center justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="text-base font-semibold">{{ __('wallet.adjust') }}</h2>
                    <p class="truncate text-sm text-slate-500" x-text="name"></p>
                </div>
                <button class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-2xl text-slate-500" type="button" @click="open = false" aria-label="{{ __('wallet.cancel') }}">&times;</button>
            </div>
            <div class="grid grid-cols-2 gap-1 rounded-lg bg-slate-100 p-1">
                <button class="h-10 rounded-md text-sm font-semibold" type="button" :class="direction === 'add' ? 'bg-white text-emerald-700 shadow' : 'text-slate-500'" @click="direction = 'add'">{{ __('wallet.add') }}</button>
                <button class="h-10 rounded-md text-sm font-semibold" type="button" :class="direction === 'remove' ? 'bg-white text-rose-700 shadow' : 'text-slate-500'" @click="direction = 'remove'">{{ __('wallet.remove') }}</button>
            </div>
            <div class="grid grid-cols-3 gap-1 rounded-lg bg-slate-100 p-1" x-show="balances">
                <template x-for="c in ['TRY', 'USD', 'EUR']" :key="c">
                    <button class="h-10 rounded-md text-sm font-semibold" type="button" :class="currency === c ? 'bg-white text-slate-900 shadow' : 'text-slate-500'" @click="pickCurrency(c)" x-text="c"></button>
                </template>
            </div>
            <div class="flex items-center justify-between text-sm">
                <span class="text-slate-500">{{ __('wallet.current_balance') }}</span>
                <span class="font-numeric font-semibold" x-text="fmt(balance)"></span>
            </div>
            <div class="flex items-center justify-between text-sm" x-show="direction === 'add' && ! unlimited">
                <span class="text-slate-500">{{ __('wallet.your_balance') }}</span>
                <span class="font-numeric font-semibold" :class="ownTooMuch() ? 'text-rose-600' : ''" x-text="fmt(own)"></span>
            </div>
            <label class="grid gap-1 text-sm">{{ __('wallet.amount') }}
                <input class="h-12 w-full rounded-md border border-[#E3E6EB] px-3 font-numeric text-lg" x-ref="amount" x-model="raw" inputmode="decimal" autocomplete="off" required>
            </label>
            <div class="flex items-center justify-between text-sm" x-show="amount()">
                <span class="text-slate-500">{{ __('wallet.after_balance') }}</span>
                <span class="font-numeric font-semibold" :class="tooMuch() ? 'text-rose-600' : ''" x-text="fmt(after())"></span>
            </div>
            <p class="text-sm text-rose-600" x-show="tooMuch()">{{ __('wallet.exceeds_balance') }}</p>
            <p class="text-sm text-rose-600" x-show="ownTooMuch()">{{ __('wallet.exceeds_own_balance') }}</p>
            <label class="grid gap-1 text-sm">{{ __('wallet.note') }}
                <input class="h-11 w-full rounded-md border border-[#E3E6EB] px-3" name="note" maxlength="2000">
            </label>
            <div class="grid grid-cols-2 gap-2 md:flex md:justify-end">
                <button class="inline-flex h-11 items-center justify-center rounded-lg border border-[#E3E6EB] bg-white px-4 text-sm" type="button" @click="open = false">{{ __('wallet.cancel') }}</button>
                <button class="inline-flex h-11 items-center justify-center rounded-lg bg-[#161A22] px-4 text-sm text-white disabled:opacity-40" type="submit" :disabled="blocked()">{{ __('wallet.submit') }}</button>
            </div>
        </form>
    </div>

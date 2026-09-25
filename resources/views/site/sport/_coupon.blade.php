@php $clearId = 'coupon-clear-'.uniqid(); @endphp
<section class="js-coupon overflow-hidden rounded-xl bg-[#151A23]" data-mode="{{ $coupon['mode'] }}" data-currency="{{ $coupon['currency'] }}">
    <div class="flex border-b border-[#232B39]">
        <button class="js-mode sport-tab h-12 flex-1 text-[13px] font-extrabold tracking-wide {{ $coupon['mode'] !== 'single' ? 'sport-tab-on bg-[#1E2533] text-white' : 'bg-transparent text-[#9AA4B5]' }}" type="button" data-mode="combo">{{ __('sport.coupon.combo') }}</button>
        <button class="js-mode sport-tab h-12 flex-1 text-[13px] font-extrabold tracking-wide {{ $coupon['mode'] === 'single' ? 'sport-tab-on bg-[#1E2533] text-white' : 'bg-transparent text-[#9AA4B5]' }}" type="button" data-mode="single">{{ __('sport.coupon.single') }}</button>
    </div>
    @if (session('status'))
        <p class="px-4 pt-3 text-sm font-semibold text-[#3DD68C]" role="status">{{ session('status') }}</p>
    @endif
    @if ($errors->has('coupon'))
        <p class="px-4 pt-3 text-sm text-red-400" role="alert">{{ $errors->first('coupon') }}</p>
    @endif
    <p class="js-request-error hidden px-4 pt-3 text-sm text-red-400">{{ __('sport.errors.request') }}</p>
    @if (in_array('suspended', $coupon['warnings'], true))
        <p class="px-4 pt-3 text-sm text-red-400">{{ __('sport.coupon.suspended') }}</p>
    @endif
    @if (in_array('changed', $coupon['warnings'], true))
        <p class="px-4 pt-3 text-sm text-amber-300">{{ __('sport.coupon.changed') }}</p>
    @endif
    @forelse ($coupon['rows'] as $row)
        <div class="px-2 pt-2">
            <div class="flex gap-2.5 rounded-[10px] bg-[#1A2029] p-3">
                <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                    <p class="break-words text-[13px] font-bold">{{ sport_name($row['odd']->fixture->home) }}</p>
                    <p class="break-words text-[13px] font-bold">{{ sport_name($row['odd']->fixture->away) }}</p>
                    <p class="text-xs text-[#9AA4B5]">{{ __($row['odd']->market->name_key) }}: <span class="font-bold text-[#E8ECF3]">{{ __('sport.outcomes.'.$row['odd']->outcome) }}</span></p>
                </div>
                <div class="flex flex-col items-end gap-1">
                    <form method="POST" action="{{ route('site.sport.coupon.remove', $row['odd']) }}">
                        @csrf
                        <button class="inline-flex h-6 w-6 items-center justify-center text-[#9AA4B5]" type="submit" aria-label="{{ __('sport.coupon.remove') }}">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6 6 18M6 6l12 12"></path></svg>
                        </button>
                    </form>
                    <span class="js-selection font-numeric text-lg font-bold text-[var(--accent)]" data-selection-odd="{{ $row['shown'] }}" data-outcome="{{ $row['odd']->outcome }}">{{ $row['shown'] }}</span>
                </div>
            </div>
        </div>
    @empty
        <div class="flex flex-col items-center gap-2 px-6 py-12 text-center">
            <p class="text-[15px] font-bold">{{ __('sport.coupon.empty') }}</p>
            <p class="text-[13px] text-[#9AA4B5]">{{ __('sport.coupon.empty_hint') }}</p>
        </div>
    @endforelse
    <form class="js-coupon-form grid gap-3 px-4 pb-4 {{ count($coupon['rows']) === 0 ? 'hidden' : 'pt-3' }}" method="POST" action="{{ route('site.sport.coupon') }}">
        @csrf
        <div class="flex items-center justify-between text-[13px] text-[#9AA4B5]">
            <span>{{ __('sport.coupon.selections', ['count' => count($coupon['rows'])]) }}</span>
            <button class="text-xs font-bold underline" form="{{ $clearId }}" type="submit">{{ __('sport.coupon.clear') }}</button>
        </div>
        <label class="flex items-center gap-2.5 text-[13px] text-[#C9D1DD]">
            <input class="js-accept h-4 w-4 accent-[var(--accent)]" type="checkbox" name="accept" value="1" @checked($coupon['accept'])>
            {{ __('sport.coupon.accept') }}
        </label>
        <div class="flex items-center gap-2">
            <span class="w-16 text-xs font-bold text-[#9AA4B5]">{{ __('sport.coupon.stake') }}</span>
            <div class="flex h-11 min-w-0 flex-1 items-center justify-between rounded-lg border border-[#2A3342] bg-[#0E1117] px-3.5">
                <input class="js-stake min-w-0 flex-1 bg-transparent font-numeric text-xl font-bold outline-none" name="stake" value="{{ $coupon['stake'] }}" inputmode="decimal">
                <span class="font-bold text-[#9AA4B5]">{{ $coupon['currency'] === 'TRY' ? '₺' : $coupon['currency'] }}</span>
            </div>
        </div>
        <div class="grid grid-cols-5 gap-1.5">
            @foreach ($coupon['quick'] as $amount)
                <button class="js-quick h-9 rounded-lg text-[13px] font-bold {{ (string) $coupon['stake'] === (string) $amount ? 'border border-[var(--accent)] bg-[var(--accent)] text-[#1A1305]' : 'border border-[#2A3342] bg-transparent text-[#E8ECF3]' }}" type="button" data-amount="{{ $amount }}">{{ $amount >= 1000 ? intdiv((int) $amount, 1000).'K' : $amount }}</button>
            @endforeach
        </div>
        <div class="flex gap-2">
            <div class="flex min-w-0 flex-1 flex-col gap-0.5 rounded-lg bg-[#1A2029] px-3 py-2.5">
                <p class="text-[11px] font-bold text-[#9AA4B5]">{{ __('sport.coupon.total') }}</p>
                <p class="js-total font-numeric text-[22px] font-bold">{{ $coupon['total'] }}</p>
            </div>
            <div class="flex min-w-0 flex-1 flex-col gap-0.5 rounded-lg bg-[#13261D] px-3 py-2.5">
                <p class="text-[11px] font-bold text-[#8FD9B3]">{{ __('sport.coupon.payout') }}</p>
                <p class="js-payout font-numeric text-[22px] font-bold text-[#3DD68C]">{{ $coupon['payout'] }}</p>
            </div>
        </div>
    </form>
    <form id="{{ $clearId }}" method="POST" action="{{ route('site.sport.coupon.clear') }}">
        @csrf
    </form>
    @if (count($coupon['rows']) > 0)
        <div class="px-4 pb-4">
            @auth
                <form class="js-place" method="POST" action="{{ route('site.sport.coupon.place') }}">
                    @csrf
                    <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                    <input type="hidden" name="stake" value="{{ $coupon['stake'] }}">
                    <input type="hidden" name="mode" value="{{ $coupon['mode'] }}">
                    <input type="hidden" name="accept" value="{{ $coupon['accept'] ? '1' : '0' }}">
                    <button class="inline-flex h-[52px] w-full items-center justify-center rounded-[10px] bg-[var(--accent)] text-base font-extrabold tracking-wide text-[#1A1305]" type="submit">{{ __('sport.coupon.confirm') }}</button>
                </form>
            @else
                <a class="inline-flex h-[52px] w-full items-center justify-center rounded-[10px] bg-[var(--accent)] text-base font-extrabold tracking-wide text-[#1A1305]" href="{{ route('login') }}">{{ __('site.login') }}</a>
            @endauth
        </div>
    @endif
    <script>
        document.querySelectorAll('.js-coupon').forEach((root) => {
            if (root.dataset.ready) return;
            root.dataset.ready = '1';
            const stake = root.querySelector('.js-stake');
            const syncPlace = () => {
                const form = root.querySelector('.js-place');
                if (! form) return;
                const stakeField = form.querySelector('[name=stake]');
                const modeField = form.querySelector('[name=mode]');
                const acceptField = form.querySelector('[name=accept]');
                if (stakeField) stakeField.value = stake?.value ?? '';
                if (modeField) modeField.value = root.dataset.mode;
                if (acceptField) acceptField.value = root.querySelector('.js-accept')?.checked ? '1' : '0';
            };
            const paint = () => {
                if (! stake) return;
                root.querySelectorAll('.js-quick').forEach((button) => {
                    const on = button.dataset.amount === stake.value;
                    button.classList.toggle('bg-[var(--accent)]', on);
                    button.classList.toggle('text-[#1A1305]', on);
                    button.classList.toggle('border-[var(--accent)]', on);
                    button.classList.toggle('text-[#E8ECF3]', !on);
                    button.classList.toggle('border-[#2A3342]', !on);
                });
            };
            const persist = () => {
                syncPlace();
                if (! stake) return;
                const form = root.querySelector('.js-coupon-form');
                if (! form) return;
                const body = new FormData(form);
                body.set('stake', stake.value);
                body.set('mode', root.dataset.mode);
                if (root.querySelector('.js-accept')?.checked) body.set('accept', '1');
                fetch(form.action, { method: 'POST', body, headers: { Accept: 'application/json' } })
                    .then((response) => {
                        if (! response.ok) throw new Error('request');
                        return response.json();
                    })
                    .then((data) => {
                        const totalNode = root.querySelector('.js-total');
                        const payoutNode = root.querySelector('.js-payout');
                        if (totalNode && data.total) totalNode.textContent = data.total;
                        if (payoutNode && data.payout) payoutNode.textContent = data.payout;
                    })
                    .catch(() => {
                        const node = root.querySelector('.js-request-error');
                        if (! node) return;
                        node.classList.remove('hidden');
                        node.setAttribute('role', 'alert');
                    });
            };
            stake?.addEventListener('input', () => { paint(); persist(); });
            root.querySelectorAll('.js-quick').forEach((button) => button.addEventListener('click', () => {
                stake.value = button.dataset.amount;
                paint();
                persist();
            }));
            root.querySelectorAll('.js-mode').forEach((button) => button.addEventListener('click', () => {
                root.dataset.mode = button.dataset.mode;
                root.querySelectorAll('.js-mode').forEach((item) => {
                    const on = item === button;
                    item.classList.toggle('sport-tab-on', on);
                    item.classList.toggle('bg-[#1E2533]', on);
                    item.classList.toggle('text-white', on);
                    item.classList.toggle('text-[#9AA4B5]', !on);
                });
                paint();
                persist();
            }));
            root.querySelector('.js-accept')?.addEventListener('change', persist);
            root.querySelector('.js-place')?.addEventListener('submit', syncPlace);
            paint();
            syncPlace();
        });
    </script>
</section>

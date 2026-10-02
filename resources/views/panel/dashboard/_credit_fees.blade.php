<x-panel.card class="mt-4" :title="__('panel.credit_fees')">
    <p class="text-sm text-slate-500">{{ __('panel.credit_fee_hint') }}</p>
    @foreach ($creditFees as $block)
        <div class="mt-4">
            <p class="text-sm font-semibold">{{ $block['owner']->username }} · %{{ rtrim(rtrim($block['rate'], '0'), '.') }}</p>
            <div class="overflow-x-auto">
                <table class="mt-2 w-full text-sm">
                    <thead>
                        <tr class="text-left text-slate-500">
                            <th class="py-2 pr-3">{{ __('panel.fields.currency') }}</th>
                            <th class="py-2 pr-3 text-right">{{ __('panel.credit_fee_issued') }}</th>
                            <th class="py-2 pr-3 text-right">{{ __('panel.credit_fee_fee') }}</th>
                            <th class="py-2 pr-3 text-right">{{ __('panel.credit_fee_paid') }}</th>
                            <th class="py-2 text-right">{{ __('panel.credit_fee_due') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($block['rows'] as $row)
                            @php($cur = \App\Enums\Currency::from($row['currency']))
                            <tr class="border-t border-[#E3E6EB]">
                                <td class="py-2 pr-3">{{ $row['currency'] }}</td>
                                <td class="py-2 pr-3 text-right font-numeric">{{ \App\Support\Money::format($row['issued'], $cur) }}</td>
                                <td class="py-2 pr-3 text-right font-numeric">{{ \App\Support\Money::format($row['fee'], $cur) }}</td>
                                <td class="py-2 pr-3 text-right font-numeric">{{ \App\Support\Money::format($row['paid'], $cur) }}</td>
                                <td class="py-2 text-right font-numeric font-semibold {{ bccomp($row['due'], '0', 2) > 0 ? 'text-red-700' : 'text-emerald-800' }}">{{ \App\Support\Money::format($row['due'], $cur) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <form method="POST" action="{{ route('panel.credit-fees.payments.store') }}" class="mt-3 grid gap-2 sm:grid-cols-2">
                @csrf
                <input type="hidden" name="sub_owner_id" value="{{ $block['owner']->id }}">
                <label class="grid gap-1 text-sm">
                    <span>{{ __('panel.fields.currency') }}</span>
                    <select class="rounded-md border border-slate-300 px-3 py-2" name="currency">
                        @foreach (['TRY', 'USD', 'EUR'] as $c)
                            <option value="{{ $c }}">{{ $c }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="grid gap-1 text-sm">
                    <span>{{ __('panel.credit_fee_amount') }}</span>
                    <input class="rounded-md border border-slate-300 px-3 py-2" name="amount" inputmode="decimal" required>
                </label>
                <label class="grid gap-1 text-sm">
                    <span>{{ __('panel.credit_fee_note') }}</span>
                    <input class="rounded-md border border-slate-300 px-3 py-2" name="note" maxlength="500">
                </label>
                <div class="grid items-end">
                    <button class="inline-flex h-11 items-center justify-center rounded-lg border border-[#E3E6EB] bg-white px-3 text-sm" type="submit">{{ __('panel.credit_fee_record') }}</button>
                </div>
            </form>
        </div>
    @endforeach
    @if ($errors->any())
        <p class="mt-2 text-sm text-red-700">{{ $errors->first() }}</p>
    @endif
</x-panel.card>

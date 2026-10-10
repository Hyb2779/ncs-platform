@php
    $rates = $creditFeeRates ?? \App\Support\PlatformSetting::creditFeeRates();
@endphp
<x-panel.card class="mt-4" :title="__('panel.credit_fees')">
    <p class="text-sm text-slate-500">{{ __('panel.credit_fee_hint') }}</p>
    <form class="mt-3 flex flex-wrap items-end gap-2" method="GET">
        <label class="grid gap-1 text-sm">
            <span>{{ __('panel.reports_from') }}</span>
            <input class="h-11 rounded-md border border-slate-300 px-3" type="date" name="fee_from" value="{{ $feeFrom ?? request('fee_from') }}">
        </label>
        <label class="grid gap-1 text-sm">
            <span>{{ __('panel.reports_to') }}</span>
            <input class="h-11 rounded-md border border-slate-300 px-3" type="date" name="fee_to" value="{{ $feeTo ?? request('fee_to') }}">
        </label>
        <button class="inline-flex h-11 items-center justify-center rounded-lg bg-[#161A22] px-3 text-sm text-white" type="submit">{{ __('panel.reports_apply') }}</button>
    </form>
    <form class="mt-3 flex flex-wrap items-end gap-2" method="POST" action="{{ route('panel.credit-fees.rate') }}">
        @csrf
        @foreach (\App\Enums\Currency::values() as $code)
            <label class="grid gap-1 text-sm">
                <span>{{ $code }} · {{ __('panel.credit_fee_rate') }}</span>
                <input class="h-11 w-28 rounded-md border border-slate-300 px-3" name="rates[{{ $code }}]" inputmode="decimal" value="{{ rtrim(rtrim($rates[$code] ?? '12.00', '0'), '.') }}" required>
            </label>
        @endforeach
        <button class="inline-flex h-11 items-center justify-center rounded-lg border border-[#E3E6EB] bg-white px-3 text-sm" type="submit">{{ __('panel.save') }}</button>
    </form>
    @foreach ($creditFees as $block)
        <div class="mt-4">
            <p class="text-sm font-semibold">{{ $block['owner']->username }}</p>
            <div class="overflow-x-auto">
                <table class="mt-2 w-full text-sm">
                    <thead>
                        <tr class="text-start text-slate-500">
                            <th class="py-2 pe-3">{{ __('panel.fields.currency') }}</th>
                            <th class="py-2 pe-3 text-end">{{ __('panel.credit_fee_issued') }}</th>
                            <th class="py-2 pe-3 text-end">{{ __('panel.credit_fee_fee') }}</th>
                            <th class="py-2 pe-3 text-end">{{ __('panel.credit_fee_paid') }}</th>
                            <th class="py-2 text-end">{{ __('panel.credit_fee_due') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($block['rows'] as $row)
                            @php($cur = \App\Enums\Currency::from($row['currency']))
                            <tr class="border-t border-[#E3E6EB]">
                                <td class="py-2 pe-3">{{ $row['currency'] }}</td>
                                <td class="py-2 pe-3 text-end font-numeric">{{ \App\Support\Money::format($row['issued'], $cur) }}</td>
                                <td class="py-2 pe-3 text-end font-numeric">{{ \App\Support\Money::format($row['fee'], $cur) }}</td>
                                <td class="py-2 pe-3 text-end font-numeric">{{ \App\Support\Money::format($row['paid'], $cur) }}</td>
                                <td class="py-2 text-end font-numeric font-semibold {{ bccomp($row['due'], '0', 2) > 0 ? 'text-red-700' : 'text-emerald-800' }}">{{ \App\Support\Money::format($row['due'], $cur) }}</td>
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
                        @foreach (\App\Enums\Currency::values() as $c)
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

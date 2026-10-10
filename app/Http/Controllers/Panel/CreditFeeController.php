<?php

namespace App\Http\Controllers\Panel;

use App\Enums\Currency;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Stats\CreditFees;
use App\Support\PlatformSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Kök owner'ın alt owner'dan kredi ücreti tahsilatı girmesi. Sadece kök owner. */
class CreditFeeController extends Controller
{
    public function index(Request $request, CreditFees $fees): View
    {
        abort_unless($request->user()->isRootOwner(), 404);
        $zone = $request->user()->timezone ?: 'Europe/Istanbul';
        $from = $request->filled('fee_from') ? Carbon::parse($request->query('fee_from'), $zone)->startOfDay()->utc() : null;
        $to = $request->filled('fee_to') ? Carbon::parse($request->query('fee_to'), $zone)->addDay()->startOfDay()->utc() : null;

        return view('panel.credit_fees.index', [
            'creditFees' => $fees->summary($from, $to),
            'creditFeeRates' => PlatformSetting::creditFeeRates(),
            'feeFrom' => $request->query('fee_from'),
            'feeTo' => $request->query('fee_to'),
        ]);
    }

    public function updateRate(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isRootOwner(), 404);
        $rules = ['rates' => ['required', 'array']];
        foreach (Currency::values() as $currency) {
            $rules['rates.'.$currency] = ['required', 'numeric', 'gte:0', 'lte:100'];
        }
        $data = $request->validate($rules);
        foreach (Currency::values() as $currency) {
            PlatformSetting::putCreditFeeRate($currency, (string) $data['rates'][$currency]);
        }

        return back()->with('status', __('panel.credit_fee_rate_saved'));
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor->isRootOwner(), 404);

        $request->merge(['amount' => $this->normalize((string) $request->input('amount'))]);
        $data = $request->validate([
            'sub_owner_id' => ['required', 'integer', Rule::exists('users', 'id')->where('role', 'owner')->whereNotNull('parent_id')],
            'currency' => ['required', Rule::in(Currency::values())],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $amount = bcadd((string) $data['amount'], '0', 2);

        DB::transaction(function () use ($actor, $data, $amount, $request) {
            $id = DB::table('credit_fee_payments')->insertGetId([
                'sub_owner_id' => $data['sub_owner_id'], 'currency' => $data['currency'], 'amount' => $amount,
                'note' => $data['note'] ?? null, 'created_by' => $actor->id, 'created_at' => now(),
            ]);
            DB::table('activity_logs')->insert([
                'actor_id' => $actor->id, 'action' => 'credit_fee.payment', 'target_type' => User::class,
                'target_id' => $data['sub_owner_id'], 'ip' => $request->ip(),
                'payload' => json_encode(['payment_id' => $id, 'currency' => $data['currency'], 'amount' => $amount]),
                'created_at' => now(),
            ]);
        });

        return back()->with('status', __('panel.credit_fee_saved'));
    }

    /** 1.000,50 / 1000.50 / 1.000 kabul (bakiye panelindeki gibi). */
    private function normalize(string $raw): string
    {
        $raw = trim(str_replace(' ', '', $raw));
        if (str_contains($raw, ',')) {
            return str_replace(['.', ','], ['', '.'], $raw);
        }
        if (preg_match('/^\d{1,3}(\.\d{3})+$/', $raw) === 1) {
            return str_replace('.', '', $raw);
        }

        return $raw;
    }
}

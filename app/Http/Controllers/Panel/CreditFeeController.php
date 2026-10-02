<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Kök owner'ın alt owner'dan kredi ücreti tahsilatı girmesi. Sadece kök owner. */
class CreditFeeController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor->isRootOwner(), 404);

        $request->merge(['amount' => $this->normalize((string) $request->input('amount'))]);
        $data = $request->validate([
            'sub_owner_id' => ['required', 'integer', Rule::exists('users', 'id')->where('role', 'owner')->whereNotNull('parent_id')->whereNotNull('credit_fee_rate')],
            'currency' => ['required', Rule::in(['TRY', 'USD', 'EUR'])],
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

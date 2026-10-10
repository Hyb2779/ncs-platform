<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $ids = DB::table('users')->where('role', 'owner')->whereNotNull('parent_id')->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('wallets')->whereIn('user_id', $ids)->where('balance', '>=', 0)->update(['allow_negative' => false]);
        }

        $legacy = DB::table('platform_settings')->where('key', 'credit_fee_rate')->value('value') ?? '12.00';
        foreach (['TRY', 'USD', 'EUR'] as $currency) {
            DB::table('platform_settings')->updateOrInsert(
                ['key' => 'credit_fee_rate_'.$currency],
                ['value' => $legacy],
            );
        }
    }

    public function down(): void
    {
        $ids = DB::table('users')->where('role', 'owner')->whereNotNull('parent_id')->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('wallets')->whereIn('user_id', $ids)->update(['allow_negative' => true]);
        }
        DB::table('platform_settings')->whereIn('key', [
            'credit_fee_rate_TRY', 'credit_fee_rate_USD', 'credit_fee_rate_EUR',
        ])->delete();
    }
};

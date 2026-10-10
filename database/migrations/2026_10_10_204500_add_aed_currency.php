<?php

use App\Services\Sport\SportLimitCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE users MODIFY currency ENUM('TRY', 'USD', 'EUR', 'AED') NOT NULL");
            DB::statement("ALTER TABLE wallets MODIFY currency ENUM('TRY', 'USD', 'EUR', 'AED') NOT NULL");
        }

        $now = now();
        $rootIds = DB::table('users')->where('role', 'owner')->whereNull('parent_id')->pluck('id')
            ->map(fn ($id) => (int) $id)->all();

        foreach (DB::table('users')->whereIn('role', ['owner', 'superadmin'])->get(['id']) as $account) {
            $exists = DB::table('wallets')->where('user_id', $account->id)->where('currency', 'AED')->exists();
            if ($exists) {
                continue;
            }
            DB::table('wallets')->insert([
                'user_id' => $account->id,
                'currency' => 'AED',
                'balance' => 0,
                'allow_negative' => in_array((int) $account->id, $rootIds, true),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (Schema::hasTable('sport_limits') && ! DB::table('sport_limits')->whereNull('user_id')->where('currency', 'AED')->exists()) {
            DB::table('sport_limits')->insert(SportLimitCatalog::for('AED') + [
                'user_id' => null,
                'currency' => 'AED',
                'limit_key' => 'owner:AED',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $rate = DB::table('platform_settings')->where('key', 'credit_fee_rate_TRY')->value('value')
            ?? DB::table('platform_settings')->where('key', 'credit_fee_rate')->value('value')
            ?? '12.00';
        if (Schema::hasTable('platform_settings')) {
            DB::table('platform_settings')->updateOrInsert(
                ['key' => 'credit_fee_rate_AED'],
                ['value' => $rate],
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('platform_settings')) {
            DB::table('platform_settings')->where('key', 'credit_fee_rate_AED')->delete();
        }
        if (Schema::hasTable('sport_limits')) {
            DB::table('sport_limits')->whereNull('user_id')->where('currency', 'AED')->delete();
        }
        DB::table('wallets')->where('currency', 'AED')->where('balance', 0)->delete();

        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE users MODIFY currency ENUM('TRY', 'USD', 'EUR') NOT NULL");
            DB::statement("ALTER TABLE wallets MODIFY currency ENUM('TRY', 'USD', 'EUR') NOT NULL");
        }
    }
};

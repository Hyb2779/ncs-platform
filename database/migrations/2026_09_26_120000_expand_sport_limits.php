<?php

use App\Services\Sport\SportLimitCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sport_limits_next', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->char('currency', 3);
            $table->string('limit_key')->unique();
            $table->boolean('cash_out_enabled')->default(false);
            foreach ([
                'min_stake', 'max_stake_general', 'max_stake_single', 'max_stake_live',
                'max_stake_per_fixture', 'max_stake_per_outcome', 'repeat_limit_single', 'repeat_limit_combo',
                'daily_max', 'max_payout_general', 'max_payout_single', 'max_payout_live', 'max_payout_live_single',
            ] as $money) {
                $table->decimal($money, 18, 2)->nullable();
            }
            foreach ([
                'min_coupon_odds', 'max_coupon_odds', 'min_odds_prematch', 'max_odds_prematch', 'min_odds_live', 'max_odds_live',
            ] as $odds) {
                $table->decimal($odds, 8, 2)->nullable();
            }
            $table->unsignedSmallInteger('max_selections')->nullable();
            $table->unsignedSmallInteger('live_close_minute')->nullable();
            $table->unsignedInteger('cancel_minutes')->nullable();
            $table->timestamps();
        });

        foreach (DB::table('sport_limits')->whereNotNull('superadmin_id')->get() as $row) {
            $currency = DB::table('users')->where('id', $row->superadmin_id)->value('currency') ?? 'TRY';
            DB::table('sport_limits_next')->insert([
                'user_id' => $row->superadmin_id,
                'currency' => $currency,
                'limit_key' => 'user:'.$row->superadmin_id,
                'cash_out_enabled' => false,
                'min_stake' => $row->min_stake,
                'max_stake_general' => $row->max_stake,
                'max_stake_single' => $row->max_stake,
                'max_stake_live' => $row->max_stake,
                'max_stake_per_fixture' => $row->max_stake,
                'max_stake_per_outcome' => $row->max_stake,
                'repeat_limit_single' => $row->max_stake,
                'repeat_limit_combo' => $row->max_stake,
                'daily_max' => $row->daily_max,
                'min_coupon_odds' => $row->min_total_odds,
                'max_coupon_odds' => '500.00',
                'max_payout_general' => $row->max_win,
                'max_payout_single' => $row->max_win,
                'max_payout_live' => $row->max_win,
                'max_payout_live_single' => $row->max_win,
                'min_odds_prematch' => $row->min_odd,
                'max_odds_prematch' => '30.00',
                'min_odds_live' => $row->min_odd,
                'max_odds_live' => '30.00',
                'max_selections' => $row->combo_max,
                'live_close_minute' => 85,
                'cancel_minutes' => $row->cancel_minutes,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        }

        foreach (SportLimitCatalog::all() as $currency => $values) {
            DB::table('sport_limits_next')->insert($values + [
                'user_id' => null,
                'currency' => $currency,
                'limit_key' => 'owner:'.$currency,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::drop('sport_limits');
        Schema::rename('sport_limits_next', 'sport_limits');
    }

    public function down(): void
    {
        Schema::dropIfExists('sport_limits');
    }
};

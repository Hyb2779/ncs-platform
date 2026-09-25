<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sport_limits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('superadmin_id')->nullable()->unique()->constrained('users');
            $table->decimal('min_stake', 18, 2);
            $table->decimal('max_stake', 18, 2);
            $table->decimal('max_win', 18, 2);
            $table->unsignedSmallInteger('combo_min')->default(2);
            $table->unsignedSmallInteger('combo_max');
            $table->decimal('min_total_odds', 8, 2);
            $table->decimal('min_odd', 8, 2);
            $table->decimal('daily_max', 18, 2);
            $table->unsignedInteger('cancel_minutes')->default(0);
            $table->timestamps();
        });

        DB::table('sport_limits')->insert([
            'superadmin_id' => null,
            'min_stake' => '1.00',
            'max_stake' => '10000.00',
            'max_win' => '100000.00',
            'combo_min' => 2,
            'combo_max' => 20,
            'min_total_odds' => '1.01',
            'min_odd' => '1.01',
            'daily_max' => '50000.00',
            'cancel_minutes' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::create('coupon_placements', function (Blueprint $table) {
            $table->id();
            $table->string('client_key')->unique();
            $table->foreignId('user_id')->constrained('users');
            $table->timestamps();
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('coupon_no', 16)->unique();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('superadmin_id')->nullable()->constrained('users');
            $table->string('client_key')->index();
            $table->enum('type', ['combo', 'single']);
            $table->decimal('stake', 18, 2);
            $table->decimal('total_odds', 8, 2);
            $table->decimal('potential_win', 18, 2);
            $table->enum('status', ['pending', 'won', 'lost', 'refunded', 'cancelled']);
            $table->boolean('accept_odds_change')->default(false);
            $table->text('note')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('device')->nullable();
            $table->timestamp('placed_at');
            $table->timestamp('settled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users');
            $table->string('cancel_reason')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'placed_at']);
            $table->index('status');
        });

        Schema::create('coupon_selections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained('coupons');
            $table->foreignId('fixture_id')->constrained('sport_fixtures');
            $table->string('market_code', 16);
            $table->string('outcome', 32);
            $table->decimal('odds', 8, 2);
            $table->decimal('raw_odds', 8, 2);
            $table->timestamp('kickoff');
            $table->enum('status', ['pending', 'won', 'lost', 'void']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_selections');
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('coupon_placements');
        Schema::dropIfExists('sport_limits');
    }
};

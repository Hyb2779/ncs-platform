<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sport_fixtures', function (Blueprint $table) {
            $table->unsignedSmallInteger('ft_home')->nullable()->after('ht_away');
            $table->unsignedSmallInteger('ft_away')->nullable()->after('ft_home');
            $table->timestamp('settled_at')->nullable()->after('ft_away');
            $table->string('score_source', 16)->default('api')->after('settled_at');
        });

        Schema::table('coupon_selections', function (Blueprint $table) {
            $table->timestamp('kickoff_at')->nullable()->after('kickoff');
            $table->timestamp('settled_at')->nullable()->after('status');
        });

        DB::table('coupon_selections')->whereNull('kickoff_at')->update([
            'kickoff_at' => DB::raw('kickoff'),
        ]);

        Schema::table('coupons', function (Blueprint $table) {
            $table->unsignedInteger('settlement_revision')->default(0)->after('cancel_reason');
        });

        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE coupons MODIFY status ENUM('pending', 'won', 'lost', 'refunded', 'cancelled', 'void') NOT NULL");
        }

        if ($driver === 'sqlite') {
            Schema::table('coupons', function (Blueprint $table) {
                $table->string('status_tmp', 16)->default('pending');
            });
            DB::table('coupons')->update(['status_tmp' => DB::raw('status')]);
            Schema::table('coupons', function (Blueprint $table) {
                $table->dropIndex(['status']);
                $table->dropColumn('status');
            });
            Schema::table('coupons', function (Blueprint $table) {
                $table->renameColumn('status_tmp', 'status');
                $table->index('status');
            });
        }

        Schema::create('sport_warnings', function (Blueprint $table) {
            $table->id();
            $table->string('type', 32);
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->foreignId('fixture_id')->nullable()->constrained('sport_fixtures');
            $table->foreignId('coupon_id')->nullable()->constrained('coupons');
            $table->decimal('amount', 18, 2)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['type', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sport_warnings');

        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE coupons MODIFY status ENUM('pending', 'won', 'lost', 'refunded', 'cancelled') NOT NULL");
        }

        Schema::table('coupons', function (Blueprint $table) {
            $table->dropColumn('settlement_revision');
        });

        Schema::table('coupon_selections', function (Blueprint $table) {
            $table->dropColumn(['kickoff_at', 'settled_at']);
        });

        Schema::table('sport_fixtures', function (Blueprint $table) {
            $table->dropColumn(['ft_home', 'ft_away', 'settled_at', 'score_source']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement("ALTER TABLE coupons MODIFY status ENUM('pending', 'won', 'lost', 'refunded', 'cancelled', 'cashed_out') NOT NULL");
        DB::statement("ALTER TABLE wallet_transactions MODIFY type ENUM('mint', 'transfer_in', 'transfer_out', 'bet', 'win', 'refund', 'bonus', 'adjustment', 'cashout') NOT NULL");
    }

    public function down(): void
    {
        if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement("ALTER TABLE coupons MODIFY status ENUM('pending', 'won', 'lost', 'refunded', 'cancelled') NOT NULL");
        DB::statement("ALTER TABLE wallet_transactions MODIFY type ENUM('mint', 'transfer_in', 'transfer_out', 'bet', 'win', 'refund', 'bonus', 'adjustment') NOT NULL");
    }
};

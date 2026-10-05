<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Üye/bayi/oyuncu hareket raporları: user_id + type + created_at üzerinden sınırlı tarama. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->index(['user_id', 'type', 'created_at'], 'wallet_tx_user_type_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropIndex('wallet_tx_user_type_created_idx');
        });
    }
};

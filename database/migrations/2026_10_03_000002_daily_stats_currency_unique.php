<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * daily_stats satirlari para birimine gore ayrilir (USD/EUR bayi cirosu artik superadminin
 * varsayilan para birimine yazilmaz). Tekil anahtara currency eklenir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_stats', function (Blueprint $table): void {
            $table->unique(['stat_date', 'user_id', 'currency', 'product'], 'daily_stats_day_user_currency_product_unique');
        });
        Schema::table('daily_stats', function (Blueprint $table): void {
            $table->dropUnique('daily_stats_stat_date_user_id_product_unique');
        });
    }

    public function down(): void
    {
        Schema::table('daily_stats', function (Blueprint $table): void {
            $table->unique(['stat_date', 'user_id', 'product'], 'daily_stats_stat_date_user_id_product_unique');
        });
        Schema::table('daily_stats', function (Blueprint $table): void {
            $table->dropUnique('daily_stats_day_user_currency_product_unique');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sport_fixtures', function (Blueprint $table) {
            $table->unsignedTinyInteger('mbs')->default(1);
            $table->unsignedBigInteger('betradar_id')->nullable();
        });
        Schema::table('sport_odds', function (Blueprint $table) {
            $table->string('market_uid', 64)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('sport_odds', function (Blueprint $table) {
            $table->dropIndex(['market_uid']);
            $table->dropColumn('market_uid');
        });
        Schema::table('sport_fixtures', function (Blueprint $table) {
            $table->dropColumn(['mbs', 'betradar_id']);
        });
    }
};

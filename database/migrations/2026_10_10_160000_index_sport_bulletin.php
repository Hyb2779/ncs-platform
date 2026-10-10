<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sport_fixtures', function (Blueprint $table) {
            $table->index(['sport', 'starts_at']);
        });
        Schema::table('sport_odds', function (Blueprint $table) {
            $table->index(['fixture_id', 'suspended', 'market_id']);
        });
    }

    public function down(): void
    {
        Schema::table('sport_odds', function (Blueprint $table) {
            $table->dropIndex(['fixture_id', 'suspended', 'market_id']);
        });
        Schema::table('sport_fixtures', function (Blueprint $table) {
            $table->dropIndex(['sport', 'starts_at']);
        });
    }
};

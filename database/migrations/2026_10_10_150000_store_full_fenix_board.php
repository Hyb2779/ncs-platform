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
            $table->string('sport', 16)->default('football')->index();
            $table->json('live_meta')->nullable();
            $table->unsignedSmallInteger('offer_count')->default(0);
        });

        Schema::table('sport_odds', function (Blueprint $table) {
            $table->unsignedInteger('type_id')->nullable();
            $table->string('group_name', 160)->nullable();
            $table->string('selection_name', 160)->nullable();
            $table->decimal('handicap', 8, 2)->default(0);
            $table->unique(['fixture_id', 'type_id']);
        });

        if (! DB::table('sport_markets')->where('code', 'BOOK')->exists()) {
            DB::table('sport_markets')->insert([
                'code' => 'BOOK',
                'name_key' => 'sport.markets.BOOK',
                'api_bet_id' => 0,
                'line' => null,
                'sort_order' => 50,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('sport_odds', function (Blueprint $table) {
            $table->dropUnique(['fixture_id', 'type_id']);
            $table->dropColumn(['type_id', 'group_name', 'selection_name', 'handicap']);
        });
        Schema::table('sport_fixtures', function (Blueprint $table) {
            $table->dropColumn(['sport', 'live_meta', 'offer_count']);
        });
        DB::table('sport_markets')->where('code', 'BOOK')->delete();
    }
};

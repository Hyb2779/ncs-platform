<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sport_countries', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 8)->nullable();
            $table->string('flag')->nullable();
            $table->timestamps();
            $table->unique('name');
        });

        Schema::create('sport_leagues', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('api_id')->unique();
            $table->foreignId('country_id')->constrained('sport_countries');
            $table->string('name');
            $table->string('logo')->nullable();
            $table->unsignedSmallInteger('season')->nullable();
            $table->boolean('is_active')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('sport_teams', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('api_id')->unique();
            $table->string('name');
            $table->string('logo')->nullable();
            $table->timestamps();
        });

        Schema::create('sport_fixtures', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('api_id')->unique();
            $table->foreignId('league_id')->constrained('sport_leagues');
            $table->foreignId('home_team_id')->constrained('sport_teams');
            $table->foreignId('away_team_id')->constrained('sport_teams');
            $table->timestamp('starts_at');
            $table->string('status', 16);
            $table->string('score_home', 8)->nullable();
            $table->string('score_away', 8)->nullable();
            $table->string('ht_home', 8)->nullable();
            $table->string('ht_away', 8)->nullable();
            $table->unsignedInteger('bulletin_code')->unique();
            $table->timestamps();
        });

        Schema::create('sport_markets', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name_key');
            $table->unsignedInteger('api_bet_id');
            $table->string('line', 8)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('sport_odds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fixture_id')->constrained('sport_fixtures');
            $table->foreignId('market_id')->constrained('sport_markets');
            $table->string('outcome', 32);
            $table->decimal('raw_odd', 8, 2);
            $table->decimal('shown_odd', 8, 2);
            $table->string('direction', 8)->nullable();
            $table->boolean('suspended')->default(false);
            $table->timestamp('quoted_at')->nullable();
            $table->timestamps();
            $table->unique(['fixture_id', 'market_id', 'outcome']);
        });

        Schema::create('sport_margins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('superadmin_id')->nullable()->constrained('users');
            $table->string('layer', 16);
            $table->foreignId('league_id')->nullable()->constrained('sport_leagues');
            $table->foreignId('fixture_id')->nullable()->constrained('sport_fixtures');
            $table->string('market_code', 16)->nullable();
            $table->decimal('margin', 8, 4)->default(0);
            $table->decimal('max_odd', 8, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('sport_sync_states', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        $markets = [
            ['1X2', 'sport.markets.1X2', 1, null, 1],
            ['DC', 'sport.markets.DC', 12, null, 2],
            ['OU15', 'sport.markets.OU15', 5, '1.5', 3],
            ['OU25', 'sport.markets.OU25', 5, '2.5', 4],
            ['OU35', 'sport.markets.OU35', 5, '3.5', 5],
            ['BTTS', 'sport.markets.BTTS', 8, null, 6],
            ['HT1X2', 'sport.markets.HT1X2', 13, null, 7],
        ];

        foreach ($markets as [$code, $key, $bet, $line, $sort]) {
            DB::table('sport_markets')->insert([
                'code' => $code,
                'name_key' => $key,
                'api_bet_id' => $bet,
                'line' => $line,
                'sort_order' => $sort,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('sport_margins')->insert([
            'superadmin_id' => null,
            'layer' => 'global',
            'margin' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('sport_sync_states');
        Schema::dropIfExists('sport_margins');
        Schema::dropIfExists('sport_odds');
        Schema::dropIfExists('sport_markets');
        Schema::dropIfExists('sport_fixtures');
        Schema::dropIfExists('sport_teams');
        Schema::dropIfExists('sport_leagues');
        Schema::dropIfExists('sport_countries');
    }
};

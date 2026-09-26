<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sport_fixtures', function (Blueprint $table) {
            $table->unsignedSmallInteger('elapsed')->nullable()->after('status');
        });

        Schema::table('coupon_selections', function (Blueprint $table) {
            $table->string('placed_status', 16)->nullable()->after('kickoff_at');
            $table->unsignedSmallInteger('placed_minute')->nullable()->after('placed_status');
            $table->unsignedSmallInteger('placed_home')->nullable()->after('placed_minute');
            $table->unsignedSmallInteger('placed_away')->nullable()->after('placed_home');
        });
    }

    public function down(): void
    {
        Schema::table('coupon_selections', function (Blueprint $table) {
            $table->dropColumn(['placed_status', 'placed_minute', 'placed_home', 'placed_away']);
        });

        Schema::table('sport_fixtures', function (Blueprint $table) {
            $table->dropColumn('elapsed');
        });
    }
};

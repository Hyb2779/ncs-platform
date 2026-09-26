<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sport_fixtures', function (Blueprint $table) {
            $table->timestamp('played_at')->nullable()->after('starts_at');
        });
    }

    public function down(): void
    {
        Schema::table('sport_fixtures', function (Blueprint $table) {
            $table->dropColumn('played_at');
        });
    }
};

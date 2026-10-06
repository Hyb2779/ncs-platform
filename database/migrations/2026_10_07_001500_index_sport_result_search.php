<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sport_teams', function (Blueprint $table) {
            $table->string('name_key')->default('')->index();
        });
        Schema::table('sport_translations', function (Blueprint $table) {
            $table->string('name_key')->default('')->index();
        });
        Schema::table('sport_fixtures', function (Blueprint $table) {
            $table->index(['status', 'starts_at']);
        });

        DB::table('sport_teams')->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('sport_teams')->where('id', $row->id)->update([
                    'name_key' => sport_search_key((string) $row->name),
                ]);
            }
        });
        DB::table('sport_translations')->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('sport_translations')->where('id', $row->id)->update([
                    'name_key' => sport_search_key((string) $row->name),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('sport_fixtures', function (Blueprint $table) {
            $table->dropIndex(['status', 'starts_at']);
        });
        Schema::table('sport_translations', function (Blueprint $table) {
            $table->dropIndex(['name_key']);
            $table->dropColumn('name_key');
        });
        Schema::table('sport_teams', function (Blueprint $table) {
            $table->dropIndex(['name_key']);
            $table->dropColumn('name_key');
        });
    }
};

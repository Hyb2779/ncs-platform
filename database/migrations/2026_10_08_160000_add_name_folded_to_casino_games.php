<?php

use App\Support\GameSearch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('casino_games', function (Blueprint $table) {
            $table->string('name_folded')->nullable()->index();
        });

        DB::table('casino_games')->orderBy('id')->select(['id', 'name'])->chunkById(500, function ($games) {
            foreach ($games as $game) {
                DB::table('casino_games')->where('id', $game->id)->update([
                    'name_folded' => GameSearch::fold((string) $game->name),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('casino_games', function (Blueprint $table) {
            $table->dropIndex(['name_folded']);
            $table->dropColumn('name_folded');
        });
    }
};

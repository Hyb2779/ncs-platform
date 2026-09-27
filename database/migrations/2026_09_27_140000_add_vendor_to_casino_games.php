<?php

use App\Support\Vendors;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('casino_games', function (Blueprint $table) {
            $table->string('vendor', 32)->nullable()->index();
        });

        DB::table('casino_games')->select(['id', 'image_url'])->orderBy('id')->chunkById(500, function ($games) {
            foreach ($games as $game) {
                $vendor = Vendors::fromImage($game->image_url);
                if ($vendor !== null) {
                    DB::table('casino_games')->where('id', $game->id)->update(['vendor' => $vendor]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('casino_games', function (Blueprint $table) {
            $table->dropIndex(['vendor']);
            $table->dropColumn('vendor');
        });
    }
};

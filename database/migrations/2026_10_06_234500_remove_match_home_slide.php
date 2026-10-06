<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('home_slides')->where('key', 'match')->delete();
        Cache::forget('home:slides:rows');
    }

    public function down(): void
    {
        if (DB::table('home_slides')->where('key', 'match')->exists()) {
            return;
        }

        DB::table('home_slides')->insert([
            'key' => 'match',
            'sort_order' => 40,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};

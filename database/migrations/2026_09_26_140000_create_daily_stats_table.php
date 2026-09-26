<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_stats', function (Blueprint $table) {
            $table->id();
            $table->date('stat_date');
            $table->foreignId('user_id')->constrained('users');
            $table->char('currency', 3);
            $table->string('product', 16);
            $table->decimal('turnover', 18, 2);
            $table->decimal('payout', 18, 2);
            $table->decimal('ggr', 18, 2);
            $table->unsignedInteger('bet_count');
            $table->unsignedInteger('active_players');
            $table->unsignedInteger('new_players');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['stat_date', 'user_id', 'product']);
            $table->index(['user_id', 'stat_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_stats');
    }
};

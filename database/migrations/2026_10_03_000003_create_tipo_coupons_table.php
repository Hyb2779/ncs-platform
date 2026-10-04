<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipo_coupons', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bet_id')->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('currency', 3);
            $table->string('type', 16)->nullable();
            $table->boolean('live')->default(false);
            $table->tinyInteger('status')->nullable();
            $table->string('status_label', 24)->nullable();
            $table->decimal('stake', 14, 2)->default(0);
            $table->decimal('total_odds', 12, 2)->default(0);
            $table->decimal('potential_win', 14, 2)->default(0);
            $table->decimal('payout', 14, 2)->default(0);
            $table->unsignedSmallInteger('selection_count')->default(0);
            $table->unsignedSmallInteger('won_count')->default(0);
            $table->timestamp('placed_at')->nullable();
            $table->json('detail')->nullable();
            $table->timestamp('detail_fetched_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'placed_at']);
            $table->index(['status_label', 'placed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tipo_coupons');
    }
};

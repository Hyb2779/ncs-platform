<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipo_selections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tipo_coupon_id')->constrained('tipo_coupons')->cascadeOnDelete();
            $table->unsignedBigInteger('selection_id')->nullable();
            $table->unsignedBigInteger('event_id');
            $table->string('home_name', 160)->default('');
            $table->string('away_name', 160)->default('');
            $table->string('competition_name', 200)->nullable();
            $table->string('country_name', 120)->nullable();
            $table->unsignedSmallInteger('sport_id')->nullable();
            $table->timestamp('match_time')->nullable();
            $table->string('market_name', 160)->default('');
            $table->string('selection_name', 120)->default('');
            $table->string('handicap', 20)->nullable();
            $table->decimal('odds', 10, 2)->default(0);
            $table->string('status_label', 24)->nullable();
            $table->boolean('is_live')->default(false);
            $table->timestamps();
            $table->index(['event_id', 'market_name']);
            $table->index('match_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tipo_selections');
    }
};

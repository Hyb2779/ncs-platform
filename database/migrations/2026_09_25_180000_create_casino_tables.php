<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('casino_providers', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->enum('status', ['active', 'passive'])->default('active');
            $table->boolean('is_live')->default(false);
            $table->timestamps();
        });

        Schema::create('casino_games', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained('casino_providers');
            $table->string('external_id');
            $table->string('name');
            $table->string('category')->default('slot');
            $table->string('image_url')->nullable();
            $table->boolean('is_live')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_popular')->default(false);
            $table->timestamps();
            $table->unique(['provider_id', 'external_id']);
        });

        Schema::create('game_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('game_id')->constrained('casino_games');
            $table->string('provider');
            $table->string('token')->unique();
            $table->timestamp('opened_at');
            $table->string('ip', 45)->nullable();
            $table->string('device', 16);
        });

        Schema::create('game_rounds', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('provider_transaction_id');
            $table->string('round_id')->nullable();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('game_id')->nullable()->constrained('casino_games');
            $table->decimal('bet', 18, 2)->default(0);
            $table->decimal('win', 18, 2)->default(0);
            $table->decimal('balance_before', 18, 2)->default(0);
            $table->decimal('amount', 18, 2)->default(0);
            $table->decimal('balance_after', 18, 2)->default(0);
            $table->string('status', 32);
            $table->json('payload');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['provider', 'provider_transaction_id']);
        });

        Schema::create('casino_provider_users', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->foreignId('user_id')->constrained('users');
            $table->string('external_code');
            $table->timestamps();
            $table->unique(['provider', 'user_id']);
            $table->unique(['provider', 'external_code']);
        });

        Schema::create('casino_favorites', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('game_id')->constrained('casino_games');
            $table->primary(['user_id', 'game_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('casino_favorites');
        Schema::dropIfExists('casino_provider_users');
        Schema::dropIfExists('game_rounds');
        Schema::dropIfExists('game_sessions');
        Schema::dropIfExists('casino_games');
        Schema::dropIfExists('casino_providers');
    }
};

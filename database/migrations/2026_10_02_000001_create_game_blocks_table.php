<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_blocks', function (Blueprint $table) {
            $table->id();
            // NULL = Owner'in genel engeli; dolu = o superadminin agaci
            $table->foreignId('superadmin_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('scope', 16); // provider | vendor | category | game
            $table->string('value', 64);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['superadmin_id', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_blocks');
    }
};

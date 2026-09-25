<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sport_translations', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 16);
            $table->unsignedBigInteger('entity_id');
            $table->string('locale', 8);
            $table->string('name');
            $table->string('source', 16);
            $table->timestamps();
            $table->unique(['entity_type', 'entity_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sport_translations');
    }
};

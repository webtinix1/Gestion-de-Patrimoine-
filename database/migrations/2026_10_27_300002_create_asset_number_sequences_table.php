<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RG-03 : un compteur par portée (global, année, préfixe de catégorie), verrouillé à chaque génération.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_number_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 20)->unique();
            $table->unsignedBigInteger('last_value')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_number_sequences');
    }
};

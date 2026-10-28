<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RG-47, RG-82 : personnes désignées pour saisir l'inventaire, avec le local qui leur est confié (facultatif).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['inventory_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_participants');
    }
};

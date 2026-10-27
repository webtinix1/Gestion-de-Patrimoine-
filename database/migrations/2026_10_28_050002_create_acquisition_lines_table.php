<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Montants en FCFA entiers ; line_total est calculé (quantité × prix unitaire), jamais saisi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acquisition_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('acquisition_id')->constrained()->cascadeOnDelete();
            $table->string('designation');
            $table->foreignId('asset_category_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->bigInteger('unit_price');
            $table->boolean('tracked_individually')->default(true);
            $table->bigInteger('line_total');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acquisition_lines');
    }
};

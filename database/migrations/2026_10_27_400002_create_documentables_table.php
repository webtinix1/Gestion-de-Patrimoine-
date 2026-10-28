<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RG-65 : un document peut être rattaché à plusieurs objets (biens, puis acquisitions, mouvements…).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documentables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->morphs('documentable');
            $table->timestamps();

            $table->unique(['document_id', 'documentable_type', 'documentable_id'], 'documentables_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documentables');
    }
};

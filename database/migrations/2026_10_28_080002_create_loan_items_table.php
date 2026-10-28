<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RG-56, RG-57 : un bien prêté garde son statut précédent et ses deux états. RG-58 : un bien emprunté n'a pas de fiche.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->restrictOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('external_designation')->nullable();
            $table->string('external_reference', 20)->nullable()->unique();
            $table->bigInteger('external_value')->nullable();
            $table->foreignId('condition_out_id')->constrained('asset_conditions')->restrictOnDelete();
            $table->foreignId('condition_back_id')->nullable()->constrained('asset_conditions')->restrictOnDelete();
            $table->string('previous_status', 20)->nullable();
            $table->text('return_notes')->nullable();
            $table->timestamps();

            $table->index('asset_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_items');
    }
};

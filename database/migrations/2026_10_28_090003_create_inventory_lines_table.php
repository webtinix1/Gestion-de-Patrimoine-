<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RG-44 : une ligne par bien attendu, avec le snapshot de l'affectation et de l'état au démarrage.
 * RG-45, RG-46 : résultat constaté ; RG-47 : lock_version empêche qu'une saisie en écrase une autre ;
 * RG-48 : régularisation ultérieure liée à la ligne (resolution, resolution_ref).
 * Index unique (inventaire, bien) : un bien n'a qu'une ligne par inventaire, même scanné en même temps
 * sur deux postes (les biens non enregistrés, sans bien, ne sont pas concernés).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_id')->constrained()->restrictOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('expected')->default(true);

            $table->foreignId('expected_site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignId('expected_room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->foreignId('expected_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('expected_condition_id')->nullable()->constrained('asset_conditions')->nullOnDelete();
            $table->string('expected_status', 30)->nullable();

            $table->string('result', 30)->default('pending');
            $table->foreignId('found_site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignId('found_room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->foreignId('found_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('found_condition_id')->nullable()->constrained('asset_conditions')->nullOnDelete();
            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_at')->nullable();
            $table->unsignedInteger('lock_version')->default(0);

            $table->string('resolution', 30)->nullable();
            $table->nullableMorphs('resolution_ref');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['inventory_id', 'asset_id']);
            $table->index(['inventory_id', 'result']);
            $table->index('asset_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_lines');
    }
};

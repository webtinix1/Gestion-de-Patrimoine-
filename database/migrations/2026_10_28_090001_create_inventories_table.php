<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RG-44 à RG-48 : un inventaire porte sur un périmètre ; il ne se supprime pas.
 * perimeter_site_id et perimeter_department_id : site et département couverts par le périmètre
 * (null = plusieurs), calculés à l'enregistrement pour filtrer par le périmètre de l'utilisateur (RG-81).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventories', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->string('scope_type', 20);
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->foreignId('site_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('perimeter_site_id')->nullable()->constrained('sites')->restrictOnDelete();
            $table->foreignId('perimeter_department_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->date('starts_on')->index();
            $table->date('ends_on')->nullable();
            $table->foreignId('manager_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('planned')->index();
            $table->text('notes')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('closing_comment')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['scope_type', 'scope_id']);
            $table->index(['perimeter_site_id', 'perimeter_department_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventories');
    }
};

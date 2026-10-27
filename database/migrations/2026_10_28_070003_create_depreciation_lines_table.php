<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('depreciation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('depreciation_schedule_id')->constrained()->restrictOnDelete();
            // Exercice (année civile, Q5), relié à fiscal_years.year quand l'exercice existe.
            $table->unsignedSmallInteger('fiscal_year')->index();
            $table->unsignedSmallInteger('days')->nullable();
            $table->unsignedTinyInteger('months')->nullable();
            $table->bigInteger('base');
            $table->decimal('rate', 7, 4);
            $table->bigInteger('amount');
            $table->bigInteger('accumulated');
            $table->bigInteger('net_book_value');
            // RG-36 : ligne d'un exercice clôturé (ou antérieur au premier exercice ouvert), figée.
            $table->boolean('is_locked')->default(false);
            $table->timestamps();

            $table->unique(['depreciation_schedule_id', 'fiscal_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('depreciation_lines');
    }
};

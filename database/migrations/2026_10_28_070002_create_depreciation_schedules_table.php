<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // RG-38 : un plan par version ; un seul plan courant par bien ; une version validée est conservée.
        Schema::create('depreciation_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('method', 20);
            $table->bigInteger('original_value');
            $table->bigInteger('base_amount');
            $table->unsignedTinyInteger('duration_years');
            $table->decimal('rate', 7, 4);
            $table->decimal('declining_coefficient', 7, 4)->nullable();
            $table->bigInteger('residual_value')->default(0);
            $table->date('starts_on');
            $table->date('ends_on');
            // RG-37 : premier exercice ouvert au moment du dernier changement de paramètre (cas A6).
            $table->unsignedSmallInteger('recalculated_from_year')->nullable();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->boolean('is_current')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['asset_id', 'version']);
            $table->index(['asset_id', 'is_current']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('depreciation_schedules');
    }
};

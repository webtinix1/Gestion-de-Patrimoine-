<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // RG-37 : historique des changements de paramètres (ancienne et nouvelle valeur, motif).
        Schema::create('depreciation_parameter_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->restrictOnDelete();
            $table->uuid('batch_uuid')->index();
            $table->string('parameter', 40);
            $table->string('old_value')->nullable();
            $table->string('new_value')->nullable();
            $table->text('reason');
            $table->unsignedSmallInteger('effective_year')->nullable();
            $table->foreignId('effective_fiscal_year_id')->nullable()->constrained('fiscal_years')->nullOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('changed_at');
            $table->timestamps();

            $table->index(['asset_id', 'changed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('depreciation_parameter_changes');
    }
};

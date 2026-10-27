<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RG-15 : une acquisition regroupe les lignes d'une même facture.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acquisitions', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->date('acquired_on')->index();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('invoice_reference')->nullable();
            $table->string('funding_source', 30)->nullable();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->bigInteger('total_amount')->default(0);
            $table->string('status', 20)->default('draft')->index();
            $table->timestamp('validated_at')->nullable();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['site_id', 'department_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acquisitions');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RG-60 à RG-62 : intervention de maintenance. Le coût reste ici : il n'entre pas dans la valeur amortissable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->foreignId('asset_id')->constrained()->restrictOnDelete();
            $table->string('nature', 20);
            $table->string('reason');
            $table->date('starts_on')->index();
            $table->date('ends_on')->nullable();
            $table->string('provider_type', 10);
            $table->string('provider_name')->nullable();
            $table->bigInteger('cost')->nullable();
            $table->string('cost_reference')->nullable();
            $table->string('result', 15)->nullable();
            $table->foreignId('condition_after_id')->nullable()->constrained('asset_conditions')->restrictOnDelete();
            $table->string('status_after', 20)->nullable();
            $table->string('status', 10)->index();
            $table->text('notes')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['asset_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_jobs');
    }
};

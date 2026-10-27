<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();

            // Identification (RG-01 à RG-04 : numéro unique, immuable, jamais réutilisé).
            $table->string('number', 30)->unique();
            $table->string('designation');
            $table->foreignId('asset_category_id')->constrained()->restrictOnDelete();
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable()->index();
            $table->text('description')->nullable();
            $table->string('photo_path')->nullable();

            // Acquisition. Montants en FCFA entiers.
            $table->date('acquired_on');
            $table->date('in_service_on')->nullable();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('invoice_reference')->nullable();
            $table->bigInteger('acquisition_cost')->nullable();
            $table->string('funding_source', 30)->nullable();
            $table->unsignedBigInteger('acquisition_line_id')->nullable()->index();

            // Affectation courante (RG-20).
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('holder_id')->nullable()->constrained()->nullOnDelete();
            $table->date('assigned_since')->nullable();

            // Situation (RG-11).
            $table->foreignId('asset_condition_id')->constrained()->restrictOnDelete();
            $table->string('status', 30)->index();
            $table->text('notes')->nullable();
            $table->date('disposed_at')->nullable()->index();

            // Immobilisation (RG-30, RG-31).
            $table->boolean('is_depreciable')->default(false);
            $table->boolean('depreciation_forced_off')->default(false);
            $table->string('depreciation_method', 20)->nullable();
            $table->unsignedTinyInteger('duration_years')->nullable();
            $table->bigInteger('residual_value')->default(0);
            $table->decimal('declining_coefficient', 7, 4)->nullable();
            $table->string('asset_account', 20)->nullable();
            $table->string('depreciation_account', 20)->nullable();

            // RG-86 : version incrémentée à chaque enregistrement, pour détecter les modifications concurrentes.
            $table->unsignedInteger('lock_version')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['site_id', 'department_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};

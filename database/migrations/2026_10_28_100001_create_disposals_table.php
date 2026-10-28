<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RG-50 à RG-54 : sortie du patrimoine, une seule par bien, jamais modifiée ni supprimée.
 * RG-39 : valeur d'origine, cumul et VNC à la date de sortie figés sur la sortie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disposals', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            // RG-50 : un bien ne sort qu'une fois.
            $table->foreignId('asset_id')->unique()->constrained()->restrictOnDelete();
            $table->date('disposed_on')->index();
            $table->string('reason', 20)->index();
            $table->string('decided_by');
            $table->string('decision_reference')->nullable();
            $table->string('buyer_or_beneficiary')->nullable();
            $table->bigInteger('sale_price')->nullable();
            $table->text('notes')->nullable();

            $table->bigInteger('original_value');
            $table->bigInteger('accumulated_depreciation');
            $table->bigInteger('net_book_value');
            // Statut du bien avant la sortie (journal, lecture).
            $table->string('previous_status', 20);

            $table->string('accounting_status', 20)->default('pending')->index();
            $table->foreignId('accounting_validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accounting_validated_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disposals');
    }
};

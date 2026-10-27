<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RG-21 : un mouvement garde l'affectation précédente (from_*) et la nouvelle (to_*). Jamais modifié ni supprimé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->restrictOnDelete();
            $table->string('type', 20)->index();
            $table->date('moved_on')->index();
            $table->string('reason')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('from_site_id')->nullable()->constrained('sites')->restrictOnDelete();
            $table->foreignId('from_department_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->foreignId('from_service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->foreignId('from_room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->foreignId('from_holder_id')->nullable()->constrained('holders')->nullOnDelete();

            $table->foreignId('to_site_id')->constrained('sites')->restrictOnDelete();
            $table->foreignId('to_department_id')->constrained('departments')->restrictOnDelete();
            $table->foreignId('to_service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->foreignId('to_room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->foreignId('to_holder_id')->nullable()->constrained('holders')->nullOnDelete();

            // RG-23 : les mouvements d'un même transfert groupé partagent cet identifiant.
            $table->uuid('batch_uuid')->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['asset_id', 'moved_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movements');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RG-63, RG-64 : signalement d'un responsable de département. Il ne modifie jamais le bien.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_reports', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->foreignId('asset_id')->constrained()->restrictOnDelete();
            $table->string('type', 15);
            $table->foreignId('reported_condition_id')->nullable()->constrained('asset_conditions')->restrictOnDelete();
            $table->foreignId('reported_room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->foreignId('reported_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('urgency', 10);
            $table->text('description');
            $table->string('photo_path')->nullable();
            $table->string('status', 15)->index();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->nullableMorphs('handled_ref');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['asset_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_reports');
    }
};

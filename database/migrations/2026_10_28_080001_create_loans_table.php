<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RG-55, RG-58 : un prêt (out) ou un emprunt (in). Rien ne se supprime.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->string('direction', 3)->index();
            $table->string('borrower_type', 10);
            $table->string('borrower_name');
            $table->string('borrower_contact')->nullable();
            $table->foreignId('department_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->date('out_on')->index();
            $table->date('due_on')->index();
            $table->date('returned_on')->nullable();
            $table->foreignId('responsible_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->string('status', 10)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'due_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loans');
    }
};

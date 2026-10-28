<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // RG-90, RG-91 : une alerte par cause (empreinte unique), recalculée toutes les heures ; elle est
        // « résolue » quand sa cause est levée. Le périmètre se lit sur l'objet concerné (subject), jamais copié ici.
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->string('type', 30)->index();
            $table->string('fingerprint', 100)->unique();
            $table->string('subject_type', 100);
            $table->unsignedBigInteger('subject_id');
            // Date qui motive l'alerte (échéance, début, fin d'amortissement…).
            $table->date('event_on')->nullable();
            $table->timestamp('raised_at');
            $table->timestamp('resolved_at')->nullable()->index();
            $table->timestamps();
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RG-81 : périmètre de l'utilisateur. Aucune ligne = tous.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_user', function (Blueprint $table) {
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['site_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_user');
    }
};

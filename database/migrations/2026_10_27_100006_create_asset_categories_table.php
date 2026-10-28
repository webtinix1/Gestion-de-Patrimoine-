<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('prefix', 3)->unique();
            $table->string('depreciable', 20)->default('yes');
            $table->string('default_method', 20)->default('linear');
            $table->unsignedTinyInteger('default_duration_years')->nullable();
            $table->string('asset_account', 20)->nullable();
            $table->string('depreciation_account', 20)->nullable();
            $table->string('expense_account', 20)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_categories');
    }
};

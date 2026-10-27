<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('label_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->decimal('width_mm', 5, 1);
            $table->decimal('height_mm', 5, 1);
            $table->string('sheet_layout', 20);
            $table->string('code_type', 20);
            $table->string('header_text')->nullable();
            $table->boolean('show_designation')->default(true);
            $table->boolean('show_department')->default(true);
            $table->boolean('show_site')->default(false);
            $table->boolean('show_room')->default(false);
            $table->boolean('show_logo')->default(false);
            $table->boolean('is_default')->default(false)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('label_templates');
    }
};

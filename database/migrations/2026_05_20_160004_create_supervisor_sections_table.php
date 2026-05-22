<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supervisor_sections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignUuid('supervisor_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('section_id')->constrained('sections')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['supervisor_user_id', 'section_id']);
            $table->index(['empresa_id', 'supervisor_user_id']);
            $table->index(['empresa_id', 'section_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supervisor_sections');
    }
};

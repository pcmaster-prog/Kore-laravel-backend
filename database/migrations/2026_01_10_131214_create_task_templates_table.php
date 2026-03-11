<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
{
    Schema::create('task_templates', function (Blueprint $table) {
        $table->uuid('id')->primary();

        $table->foreignUuid('empresa_id')->constrained('empresas')->cascadeOnDelete();
        $table->foreignUuid('created_by')->constrained('users')->nullOnDelete();

        $table->string('title', 180);
        $table->text('description')->nullable();

        // Instrucciones: puede ser texto o checklist en JSON
        $table->jsonb('instructions')->nullable(); // [{text, required}] o {text:"..."}

        $table->integer('estimated_minutes')->nullable(); // duración estimada
        $table->string('priority')->default('medium'); // low|medium|high|urgent

        $table->boolean('is_active')->default(true);

        // etiquetas/categorías (opcionales)
        $table->jsonb('tags')->nullable();

        // flexible para el futuro
        $table->jsonb('meta')->nullable();

        $table->timestamps();

        $table->index(['empresa_id','is_active']);
        $table->index(['empresa_id','priority']);
    });
}


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_templates');
    }
};

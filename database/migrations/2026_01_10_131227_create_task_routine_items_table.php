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
    Schema::create('task_routine_items', function (Blueprint $table) {
        $table->uuid('id')->primary();

        $table->foreignUuid('empresa_id')->constrained('empresas')->cascadeOnDelete();
        $table->foreignUuid('routine_id')->constrained('task_routines')->cascadeOnDelete();
        $table->foreignUuid('template_id')->constrained('task_templates')->cascadeOnDelete();

        // orden visual en el catálogo del día
        $table->integer('sort_order')->default(0);

        $table->boolean('is_active')->default(true);

        $table->timestamps();

        $table->unique(['routine_id','template_id']);
        $table->index(['empresa_id','routine_id','is_active']);
    });
        }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_routine_items');
    }
};

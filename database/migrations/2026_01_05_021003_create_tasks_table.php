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
    Schema::create('tasks', function (Blueprint $table) {
        $table->uuid('id')->primary();

        $table->foreignUuid('empresa_id')->constrained('empresas')->cascadeOnDelete();
        $table->foreignUuid('created_by')->constrained('users')->nullOnDelete();

        $table->string('title', 180);
        $table->text('description')->nullable();

        // prioridad generalizable
        $table->string('priority')->default('medium'); // low|medium|high|urgent

        // status global de la tarea (resumen)
        $table->string('status')->default('open'); // open|in_progress|completed|cancelled

        // opcional: fecha objetivo
        $table->timestamp('due_at')->nullable();

        // datos flexibles por empresa (sin romper esquema)
        $table->jsonb('meta')->nullable();

        $table->timestamps();

        $table->index(['empresa_id','status']);
        $table->index(['empresa_id','priority']);
    });
}


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};

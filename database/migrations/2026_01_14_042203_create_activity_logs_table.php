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
    Schema::create('activity_logs', function (Blueprint $table) {
        $table->uuid('id')->primary();

        $table->foreignUuid('empresa_id')->constrained('empresas')->cascadeOnDelete();

        // quién lo hizo (nullable para eventos del sistema a futuro)
        $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();

        // “actor” opcional: empleado vinculado (si aplica)
        $table->foreignUuid('empleado_id')->nullable()->constrained('empleados')->nullOnDelete();

        // qué pasó
        $table->string('action', 80); // task.created, task.assigned, task.status_changed, evidence.uploaded, etc.
        $table->string('entity_type', 80)->nullable(); // task, task_template, attendance, etc.
        $table->uuid('entity_id')->nullable(); // id de la entidad

        // datos adicionales (antes/después, ids afectados, etc.)
        $table->jsonb('meta')->nullable();

        // request context mínimo útil
        $table->string('ip', 60)->nullable();
        $table->string('user_agent', 250)->nullable();

        $table->timestamps();

        $table->index(['empresa_id','action']);
        $table->index(['empresa_id','entity_type','entity_id']);
        $table->index(['empresa_id','created_at']);
    });
}


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};

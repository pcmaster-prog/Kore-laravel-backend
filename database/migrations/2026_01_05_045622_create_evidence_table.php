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
    Schema::create('evidences', function (Blueprint $table) {
        $table->uuid('id')->primary();

        $table->foreignUuid('empresa_id')->constrained('empresas')->cascadeOnDelete();

        // Quién sube la evidencia
        $table->foreignUuid('uploaded_by')->constrained('users')->nullOnDelete();

        // Para trazabilidad operativa (opcional pero recomendado)
        $table->foreignUuid('empleado_id')->nullable()->constrained('empleados')->nullOnDelete();

        // Asociación a tarea/asignación (MVP: se asocia después del upload)
        $table->foreignUuid('task_id')->nullable()->constrained('tasks')->nullOnDelete();
        $table->foreignUuid('task_assignee_id')->nullable()->constrained('task_assignees')->nullOnDelete();

        // Storage
        $table->string('disk')->default('s3'); // o local/public
        $table->string('path'); // ruta interna
        $table->string('original_name')->nullable();
        $table->string('mime')->nullable();
        $table->bigInteger('size')->nullable();

        // Metadata flexible (EXIF, device, gps, etc si luego lo quieres)
        $table->jsonb('meta')->nullable();

        $table->timestamps();

        $table->index(['empresa_id', 'task_id']);
        $table->index(['empresa_id', 'empleado_id']);
    });
}


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('evidence');
    }
};

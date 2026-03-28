<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('desempeno_evaluaciones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignUuid('employee_evaluation_id')->constrained('employee_evaluations')->cascadeOnDelete();
            $table->foreignUuid('evaluador_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('evaluado_id')->constrained('empleados')->cascadeOnDelete();
            $table->string('evaluador_rol', 20); // 'admin' | 'supervisor'

            // 8 criterios — 1 a 5
            $table->unsignedTinyInteger('puntualidad');
            $table->unsignedTinyInteger('responsabilidad');
            $table->unsignedTinyInteger('actitud_trabajo');
            $table->unsignedTinyInteger('orden_limpieza');
            $table->unsignedTinyInteger('atencion_cliente');
            $table->unsignedTinyInteger('trabajo_equipo');
            $table->unsignedTinyInteger('iniciativa');
            $table->unsignedTinyInteger('aprendizaje_adaptacion');

            // Acciones a tomar (array)
            $table->json('acciones')->nullable();
            // Valores posibles: 'mantener_desempeno', 'capacitacion', 'llamada_atencion', 'seguimiento_30_dias'

            $table->text('observaciones')->nullable();
            $table->timestamps();

            // Un evaluador solo puede evaluar una vez por activación
            $table->unique(['employee_evaluation_id', 'evaluador_id']);
            $table->index(['empresa_id', 'evaluado_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('desempeno_evaluaciones');
    }
};

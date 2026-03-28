<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('desempeno_peer_evaluaciones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignUuid('employee_evaluation_id')->constrained('employee_evaluations')->cascadeOnDelete();
            $table->foreignUuid('evaluador_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('evaluado_id')->constrained('empleados')->cascadeOnDelete();

            // 4 criterios — 1 a 5
            $table->unsignedTinyInteger('colaboracion');
            $table->unsignedTinyInteger('puntualidad');
            $table->unsignedTinyInteger('actitud');
            $table->unsignedTinyInteger('comunicacion');

            $table->timestamps();

            // Un compañero solo puede evaluar una vez por activación
            $table->unique(['employee_evaluation_id', 'evaluador_id']);
            // No puede evaluarse a sí mismo (se valida en el controller)
            $table->index(['empresa_id', 'evaluado_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('desempeno_peer_evaluaciones');
    }
};

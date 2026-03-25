<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gondola_ordenes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('empresa_id');
            $table->foreign('empresa_id')->references('id')->on('empresas')->onDelete('cascade');
            $table->uuid('gondola_id');
            $table->foreign('gondola_id')->references('id')->on('gondolas')->onDelete('cascade');
            $table->uuid('empleado_id');
            $table->foreign('empleado_id')->references('id')->on('empleados')->onDelete('cascade');
            // Status flow: pendiente → en_proceso → completado → aprobado | rechazado
            $table->string('status', 20)->default('pendiente');
            $table->string('evidencia_url', 500)->nullable();
            $table->string('notas_empleado', 500)->nullable();
            $table->string('notas_rechazo', 500)->nullable();
            $table->uuid('approved_by')->nullable();
            $table->foreign('approved_by')->references('id')->on('users')->onDelete('set null');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['empresa_id', 'status'], 'idx_gondola_ordenes_empresa');
            $table->index(['empleado_id', 'status'], 'idx_gondola_ordenes_empleado');
            $table->index('gondola_id', 'idx_gondola_ordenes_gondola');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gondola_ordenes');
    }
};

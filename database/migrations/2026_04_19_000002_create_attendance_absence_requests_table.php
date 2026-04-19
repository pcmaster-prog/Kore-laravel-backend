<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_absence_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('empresa_id');
            $table->uuid('empleado_id');
            $table->date('date');                          // Día que solicita ausentarse
            $table->text('motivo');                        // Justificación escrita
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->uuid('reviewed_by')->nullable();       // Admin/supervisor que revisó
            $table->timestamp('reviewed_at')->nullable();
            $table->string('reviewer_note', 400)->nullable(); // Nota del revisor al responder
            $table->timestamps();

            $table->foreign('empresa_id')->references('id')->on('empresas')->onDelete('cascade');
            $table->foreign('empleado_id')->references('id')->on('empleados')->onDelete('cascade');
            $table->index(['empresa_id', 'status']);
            $table->index(['empresa_id', 'empleado_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_absence_requests');
    }
};

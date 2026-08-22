<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Solicitudes del empleado: "olvidé marcar mi entrada/salida a las HH:MM".
        // El admin/supervisor las aprueba (aplica la hora al día) o rechaza.
        Schema::create('attendance_correction_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignUuid('empleado_id')->constrained('empleados')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('date');
            $table->enum('type', ['check_in', 'check_out']);
            $table->string('requested_time', 5); // HH:MM
            $table->text('motivo');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('reviewer_note')->nullable();
            $table->timestamps();

            $table->index(['empresa_id', 'status', 'date']);
            $table->index(['empleado_id', 'date', 'type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_correction_requests');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('late_arrival_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignUuid('empleado_id')->constrained('empleados')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('date');
            $table->text('motivo');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('reviewer_note')->nullable();
            $table->timestamps();

            $table->index(['empresa_id', 'date', 'status']);
            $table->index(['empleado_id', 'date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('late_arrival_requests');
    }
};

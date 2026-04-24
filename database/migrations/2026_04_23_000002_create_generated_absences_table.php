<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generated_absences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('empleado_id')->constrained('empleados')->cascadeOnDelete();
            $table->foreignUuid('empresa_id')->constrained('empresas')->cascadeOnDelete();

            $table->string('period_key', 7);  // "2026-04"
            $table->enum('type', ['late_accumulation', 'unjustified', 'justified'])->default('late_accumulation');
            $table->boolean('affects_rest_day_payment')->default(true);
            $table->text('note')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['empleado_id', 'period_key'], 'idx_emp_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generated_absences');
    }
};

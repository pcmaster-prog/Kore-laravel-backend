<?php
// database/migrations/xxxx_create_payroll_tables.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Periodo de nómina (una semana: domingo → sábado)
        Schema::create('payroll_periods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('empresa_id')->constrained('empresas')->cascadeOnDelete();

            $table->date('week_start');  // domingo
            $table->date('week_end');    // sábado

            // draft = editable | approved = cerrado
            $table->string('status')->default('draft');

            $table->decimal('total_amount', 12, 2)->default(0);
            $table->decimal('total_adjustments', 12, 2)->default(0);
            $table->decimal('total_bonuses', 12, 2)->default(0);

            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();

            $table->unique(['empresa_id', 'week_start']);
            $table->index(['empresa_id', 'status']);
        });

        // Una línea por empleado por periodo
        Schema::create('payroll_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignUuid('payroll_period_id')->constrained('payroll_periods')->cascadeOnDelete();
            $table->foreignUuid('empleado_id')->constrained('empleados')->cascadeOnDelete();

            // Snapshot del tipo y tarifa al momento de generar
            $table->string('payment_type');       // hourly | daily
            $table->decimal('rate', 10, 2);       // tarifa por hora o por día

            // Horas o días trabajados calculados de asistencia
            $table->decimal('units', 8, 2)->default(0);    // horas o días
            $table->decimal('rest_days_paid', 4, 0)->default(0); // días de descanso pagados (solo daily)
            $table->decimal('subtotal', 12, 2)->default(0);

            // Ajuste manual del admin (puede ser negativo)
            $table->decimal('adjustment_amount', 12, 2)->default(0);
            $table->string('adjustment_note')->nullable();

            // Bono extra
            $table->decimal('bonus_amount', 12, 2)->default(0);
            $table->string('bonus_note')->nullable();

            $table->decimal('total', 12, 2)->default(0);

            $table->timestamps();

            $table->unique(['payroll_period_id', 'empleado_id']);
            $table->index(['empresa_id', 'payroll_period_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_entries');
        Schema::dropIfExists('payroll_periods');
    }
};
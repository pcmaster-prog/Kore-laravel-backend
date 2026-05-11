<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('payroll_period_id')->constrained('payroll_periods')->onDelete('cascade');
            $table->foreignUuid('empleado_id')->constrained('empleados')->onDelete('cascade');
            $table->foreignUuid('user_id')->constrained('users')->onDelete('cascade');

            // Folio y estado
            $table->string('folio', 20)->unique();
            $table->enum('status', ['pending', 'signed', 'disputed'])->default('pending');

            // Período
            $table->date('period_start');
            $table->date('period_end');
            $table->date('payment_date')->nullable();

            // Datos del empleado (snapshot al momento de generar)
            $table->string('employee_name');
            $table->string('position_title')->nullable();
            $table->string('nss', 20)->nullable();
            $table->string('rfc', 13)->nullable();
            $table->string('curp', 18)->nullable();
            $table->decimal('daily_salary', 12, 2)->default(0);
            $table->decimal('sbc', 12, 2)->default(0); // Salario Base de Cotización
            $table->unsignedTinyInteger('days_worked')->default(0);

            // Percepciones (desglose)
            $table->json('perceptions')->nullable();
            $table->decimal('total_perceptions', 12, 2)->default(0);

            // Deducciones (desglose)
            $table->json('deductions')->nullable();
            $table->decimal('total_deductions', 12, 2)->default(0);

            // Totales
            $table->decimal('net_pay', 12, 2)->default(0);
            $table->string('net_pay_words')->nullable();

            // Forma de pago
            $table->string('payment_method')->default('Transferencia Electrónica');
            $table->string('bank_account')->nullable();
            $table->string('clabe')->nullable();

            // Metadata
            $table->timestamp('generated_at');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['empleado_id', 'status']);
            $table->index(['payroll_period_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_receipts');
    }
};

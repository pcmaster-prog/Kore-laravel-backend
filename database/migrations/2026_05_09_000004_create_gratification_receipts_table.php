<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gratification_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gratification_type_id')->constrained('gratification_types')->onDelete('restrict');
            $table->foreignUuid('empleado_id')->constrained('empleados')->onDelete('cascade');
            $table->foreignUuid('user_id')->constrained('users')->onDelete('cascade');

            // Folio y estado
            $table->string('folio', 20)->unique();
            $table->enum('status', ['draft', 'approved', 'signed', 'disputed'])->default('draft');

            // Ejercicio/Período
            $table->string('fiscal_year', 4);
            $table->date('issue_date');
            $table->date('payment_date')->nullable();

            // Datos del empleado (snapshot)
            $table->string('employee_name');
            $table->string('position_title')->nullable();
            $table->string('nss', 20)->nullable();
            $table->string('rfc', 13)->nullable();
            $table->string('curp', 18)->nullable();

            // Desglose del pago
            $table->json('payment_breakdown')->nullable();
            $table->decimal('total_gratification', 12, 2)->default(0);

            // Retenciones
            $table->json('retentions')->nullable();
            $table->decimal('total_retentions', 12, 2)->default(0);

            // Neto
            $table->decimal('net_amount', 12, 2)->default(0);
            $table->string('net_amount_words')->nullable();

            // Concepto específico
            $table->text('concept_description')->nullable();

            // Metadata
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();

            $table->index(['empleado_id', 'fiscal_year', 'status']);
            $table->index(['gratification_type_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gratification_receipts');
    }
};

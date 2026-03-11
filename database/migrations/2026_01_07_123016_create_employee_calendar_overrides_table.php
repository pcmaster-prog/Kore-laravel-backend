<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
{
    Schema::create('employee_calendar_overrides', function (Blueprint $table) {
        $table->uuid('id')->primary();

        $table->foreignUuid('empresa_id')->constrained('empresas')->cascadeOnDelete();
        $table->foreignUuid('empleado_id')->constrained('empleados')->cascadeOnDelete();

        $table->date('date');

        $table->string('type')->default('workday'); // workday|rest
        $table->boolean('is_paid')->default(false); // si type=rest
        $table->integer('paid_minutes')->nullable(); 

        $table->text('note')->nullable();

        $table->timestamps();

        $table->unique(['empleado_id','date']);
        $table->index(['empresa_id','date']);
    });
}


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_calendar_overrides');
    }
};

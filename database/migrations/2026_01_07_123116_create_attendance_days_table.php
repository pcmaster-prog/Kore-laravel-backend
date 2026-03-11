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
    Schema::create('attendance_days', function (Blueprint $table) {
        $table->uuid('id')->primary();

        $table->foreignUuid('empresa_id')->constrained('empresas')->cascadeOnDelete();
        $table->foreignUuid('empleado_id')->constrained('empleados')->cascadeOnDelete();

        $table->date('date');
        $table->string('status')->default('open'); // open|closed

        $table->timestamp('first_check_in_at')->nullable();
        $table->timestamp('last_check_out_at')->nullable();

     
        $table->jsonb('totals')->nullable();

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
        Schema::dropIfExists('attendance_days');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void {
  Schema::create('empleados', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('empresa_id')->constrained('empresas')->cascadeOnDelete();
    $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->string('full_name');
    $table->string('employee_code')->nullable();
    $table->string('position_title')->nullable();
    $table->string('status')->default('active'); // active|inactive
    $table->date('hired_at')->nullable();
    $table->timestamps();

    $table->index(['empresa_id','status']);
  });
}


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('empleados');
    }
};

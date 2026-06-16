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
        Schema::create('pesaje_registros', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('empleado_id')->constrained('empleados')->onDelete('cascade');
            $table->foreignId('sabor_id')->constrained('pesaje_sabors')->onDelete('cascade');
            $table->decimal('peso', 8, 3);
            $table->timestamp('fecha_registro');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pesaje_registros');
    }
};

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
        Schema::create('pesaje_sabors', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('presentacion')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('pesaje_registros', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empleado_id')->constrained('empleados')->cascadeOnDelete();
            $table->foreignId('sabor_id')->constrained('pesaje_sabors')->cascadeOnDelete();
            $table->decimal('peso', 10, 2);
            $table->date('fecha_registro');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pesaje_registros');
        Schema::dropIfExists('pesaje_sabors');
    }
};

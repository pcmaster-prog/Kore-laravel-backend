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
        Schema::create('maderas_produccions', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('empleado_id')->constrained('empleados')->onDelete('cascade');
            $table->foreignId('catalogo_id')->constrained('maderas_catalogos')->onDelete('cascade');
            $table->string('maquina')->nullable();
            $table->integer('cantidad');
            $table->timestamp('fecha_registro');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('maderas_produccions');
    }
};

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
        Schema::create('maderas_pedidos', function (Blueprint $table) {
            $table->id();
            $table->string('codigo')->unique();
            $table->string('cliente');
            $table->integer('total_unidades');
            $table->enum('status', ['pendiente', 'entregado'])->default('pendiente');
            $table->timestamp('fecha_entrega')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('maderas_pedidos');
    }
};

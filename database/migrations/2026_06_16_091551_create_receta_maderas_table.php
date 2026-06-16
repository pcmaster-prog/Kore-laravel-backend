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
        Schema::create('receta_maderas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_id')->constrained('producto_maderas');
            $table->foreignId('baston_id')->constrained('baston_maderas');
            $table->integer('cortes_por_baston');
            $table->decimal('medida_corte_cm', 5, 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('receta_maderas');
    }
};

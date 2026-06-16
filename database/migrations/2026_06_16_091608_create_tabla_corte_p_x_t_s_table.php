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
        Schema::create('tabla_corte_p_x_t_s', function (Blueprint $table) {
            $table->id();
            $table->decimal('medida_cm', 5, 2);
            $table->integer('piezas_por_tabla');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tabla_corte_p_x_t_s');
    }
};

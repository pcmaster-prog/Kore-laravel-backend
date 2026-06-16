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
        Schema::create('maderas_temporadas', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->integer('mes_inicio');
            $table->integer('mes_fin');
            $table->decimal('multiplicador', 8, 2)->default(1.0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('maderas_temporadas');
    }
};

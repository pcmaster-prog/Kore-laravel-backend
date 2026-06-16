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
        Schema::create('maderas_ensambles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalogo_id')->constrained('maderas_catalogos')->onDelete('cascade');
            $table->integer('cantidad_generada');
            $table->enum('status', ['en_proceso', 'listo'])->default('en_proceso');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('maderas_ensambles');
    }
};

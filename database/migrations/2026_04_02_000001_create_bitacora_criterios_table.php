<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bitacora_criterios', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->enum('tipo', ['positivo', 'negativo']);
            $table->boolean('activo')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bitacora_criterios');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('semaforo_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('empresa_id')->constrained('empresas')->onDelete('cascade');
            $table->foreignUuid('created_by')->constrained('users');
            $table->json('criterios_admin');
            $table->json('criterios_peer');
            $table->unsignedTinyInteger('peso_admin')->default(70);
            $table->unsignedTinyInteger('peso_peer')->default(30);
            $table->unsignedTinyInteger('umbral_verde')->default(80);
            $table->unsignedTinyInteger('umbral_amarillo')->default(60);
            $table->timestamps();

            $table->unique('empresa_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('semaforo_configs');
    }
};

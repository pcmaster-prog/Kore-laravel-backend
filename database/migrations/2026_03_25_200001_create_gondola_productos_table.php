<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gondola_productos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('empresa_id');
            $table->foreign('empresa_id')->references('id')->on('empresas')->onDelete('cascade');
            $table->uuid('gondola_id');
            $table->foreign('gondola_id')->references('id')->on('gondolas')->onDelete('cascade');
            $table->string('clave', 50)->nullable();
            $table->string('nombre', 150);
            $table->string('descripcion', 300)->nullable();
            $table->enum('unidad', ['pz', 'kg', 'caja', 'media_caja'])->default('pz');
            $table->string('foto_url', 500)->nullable();
            $table->integer('orden')->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index(['gondola_id', 'activo'], 'idx_gondola_productos_gondola');
            $table->index('empresa_id', 'idx_gondola_productos_empresa');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gondola_productos');
    }
};

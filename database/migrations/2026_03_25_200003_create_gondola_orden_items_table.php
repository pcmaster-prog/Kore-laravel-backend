<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gondola_orden_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('empresa_id');
            $table->foreign('empresa_id')->references('id')->on('empresas')->onDelete('cascade');
            $table->uuid('orden_id');
            $table->foreign('orden_id')->references('id')->on('gondola_ordenes')->onDelete('cascade');
            $table->uuid('gondola_producto_id');
            $table->foreign('gondola_producto_id')->references('id')->on('gondola_productos')->onDelete('cascade');
            // Snapshot del producto al momento de crear la orden
            $table->string('clave', 50)->nullable();
            $table->string('nombre', 150);
            $table->string('unidad', 20);
            // Cantidad que el empleado registró (NULL = aún no llenado)
            $table->decimal('cantidad', 10, 2)->nullable();
            $table->timestamps();

            $table->index('orden_id', 'idx_gondola_orden_items_orden');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gondola_orden_items');
    }
};

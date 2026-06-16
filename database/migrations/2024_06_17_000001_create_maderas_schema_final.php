<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maderas_materias_primas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('empresa_id')->nullable();
            $table->string('nombre');
            $table->enum('tipo', ['hoja_mdf_rectangular', 'hoja_mdf_circular', 'hoja_mdf_cuadrada', 'tabla_30cm', 'palo', 'consumible']);
            $table->string('dimension')->nullable();
            $table->integer('pxh')->nullable();
            $table->integer('pxt')->nullable();
            $table->decimal('stock_actual', 10, 2)->default(0);
            $table->decimal('precio_unitario', 10, 2)->default(0);
            $table->decimal('alerta_minimo', 10, 2)->default(0);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('bastones_madera', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('empresa_id')->nullable();
            $table->string('nombre');
            $table->integer('pisos');
            $table->decimal('stock', 10, 2)->default(0);
            $table->decimal('alerta_minimo', 10, 2)->default(0);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('maderas_tabla_cortes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('empresa_id')->nullable();
            $table->decimal('medida_cm', 8, 2);
            $table->integer('pxt');
            $table->decimal('precio_unitario_tabla', 10, 2);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('maderas_servicios_corte', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('empresa_id')->nullable();
            $table->string('slug');
            $table->string('nombre');
            $table->decimal('precio_fijo', 10, 2);
            $table->integer('orden_visual')->default(0);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('maderas_productos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('empresa_id')->nullable();
            $table->string('nombre');
            $table->enum('categoria', ['redonda', 'cuadrada', 'rectangular']);
            $table->string('dimension');
            $table->integer('pisos');
            $table->integer('stock_armadas')->default(0);
            $table->integer('stock_bolsas')->default(0);
            $table->foreignId('tabla_corte_id')->nullable()->constrained('maderas_tabla_cortes')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('maderas_recetas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('empresa_id')->nullable();
            $table->foreignId('producto_id')->constrained('maderas_productos')->cascadeOnDelete();
            $table->foreignId('materia_prima_id')->constrained('maderas_materias_primas')->cascadeOnDelete();
            $table->decimal('cantidad_por_unidad', 10, 4);
            $table->timestamps();
        });

        Schema::create('temporadas_madera', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('empresa_id')->nullable();
            $table->string('nombre');
            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_fin')->nullable();
            $table->boolean('activa')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('maderas_metas_temporada', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('empresa_id')->nullable();
            $table->foreignId('temporada_id')->constrained('temporadas_madera')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('maderas_productos')->cascadeOnDelete();
            $table->integer('meta_piezas')->default(0);
            $table->timestamps();
        });

        Schema::create('pedidos_madera', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('empresa_id')->nullable();
            $table->string('codigo')->nullable();
            $table->enum('status', ['pendiente', 'recibido', 'cancelado'])->default('pendiente');
            $table->string('pdf_path')->nullable();
            $table->decimal('total', 12, 2)->default(0);
            $table->date('fecha_pedido');
            $table->unsignedBigInteger('recibido_por')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('detalle_pedido_madera', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('empresa_id')->nullable();
            $table->foreignId('pedido_id')->constrained('pedidos_madera')->cascadeOnDelete();
            $table->enum('seccion_pdf', ['hojas_mdf', 'tablas_pino', 'consumibles', 'servicios_corte']);
            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('nombre_item');
            $table->decimal('cantidad', 10, 2);
            $table->decimal('precio_unitario', 10, 2);
            $table->decimal('subtotal', 10, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // For simplicity in development
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('detalle_pedido_madera');
        Schema::dropIfExists('pedidos_madera');
        Schema::dropIfExists('maderas_metas_temporada');
        Schema::dropIfExists('temporadas_madera');
        Schema::dropIfExists('maderas_recetas');
        Schema::dropIfExists('maderas_productos');
        Schema::dropIfExists('maderas_servicios_corte');
        Schema::dropIfExists('maderas_tabla_cortes');
        Schema::dropIfExists('bastones_madera');
        Schema::dropIfExists('maderas_materias_primas');
        Schema::enableForeignKeyConstraints();
    }
};

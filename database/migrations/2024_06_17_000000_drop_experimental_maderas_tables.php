<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        $tablas = [
            'detalle_pedido_madera', 'pedidos_madera', 'maderas_ensamble_piezas', 'maderas_ensambles',
            'maderas_inventarios', 'maderas_produccions', 'maderas_recetas', 'maderas_metas_temporada',
            'maderas_productos', 'maderas_materias_primas', 'maderas_tabla_cortes', 'maderas_servicios_corte',
            'bastones_madera', 'temporadas_madera', 'maderas_catalogos', 'registros_produccion_madera',
            'movimientos_inventario_madera', 'baston_maderas', 'producto_maderas', 'receta_maderas',
            'registro_produccions', 'ensamblajes', 'temporada_maderas', 'tabla_corte_p_x_t_s',
            'pesaje_registros', 'pesaje_sabors', 'sabor_pesajes', 'registro_pesajes'
        ];

        foreach ($tablas as $tabla) {
            Schema::dropIfExists($tabla);
        }

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
    }
};

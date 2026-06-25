<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MaderasDataSeeder extends Seeder
{
    public function run(): void
    {
        $empresaId = 1; // Ajusta según el ID de tu empresa de pruebas

        // 1. Materias Primas
        $hojaMdfId = DB::table('maderas_materias_primas')->insertGetId([
            'empresa_id' => $empresaId,
            'nombre' => 'MDF 40x60',
            'tipo' => 'hoja_mdf_rectangular',
            'dimension' => '40x60',
            'pxh' => 4, // Supongamos que salen 4 por hoja
            'stock_actual' => 88,
            'precio_unitario' => 180,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $grapasId = DB::table('maderas_materias_primas')->insertGetId([
            'empresa_id' => $empresaId,
            'nombre' => 'Grapas',
            'tipo' => 'consumible',
            'stock_actual' => 10000,
            'precio_unitario' => 26,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 2. Tablas de Corte (para Pino)
        $tabla30cmId = DB::table('maderas_tabla_cortes')->insertGetId([
            'empresa_id' => $empresaId,
            'medida_cm' => 30.00,
            'pxt' => 10, // Ejemplo: 10 palos por tabla
            'precio_unitario_tabla' => 260.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 3. Servicios de Corte
        DB::table('maderas_servicios_corte')->insert([
            [
                'empresa_id' => $empresaId,
                'slug' => 'recortes_rectangulares',
                'nombre' => 'Recortes Rectangulares',
                'precio_fijo' => 30.00,
                'orden_visual' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'empresa_id' => $empresaId,
                'slug' => 'recortes_circulos',
                'nombre' => 'Recortes Círculos',
                'precio_fijo' => 80.00,
                'orden_visual' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'empresa_id' => $empresaId,
                'slug' => 'tablas_en_tiras',
                'nombre' => 'Tablas en Tiras',
                'precio_fijo' => 260.00,
                'orden_visual' => 3,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // 4. Producto (Base)
        $productoId = DB::table('maderas_productos')->insertGetId([
            'empresa_id' => $empresaId,
            'nombre' => 'Base Rectangular 40x60 3 pisos',
            'categoria' => 'rectangular',
            'dimension' => '40x60',
            'pisos' => 3,
            'stock_armadas' => 30, // Ejemplo
            'stock_bolsas' => 10,  // Ejemplo
            'tabla_corte_id' => $tabla30cmId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 5. Recetas
        DB::table('maderas_recetas')->insert([
            [
                'empresa_id' => $empresaId,
                'producto_id' => $productoId,
                'materia_prima_id' => $hojaMdfId,
                'cantidad_por_unidad' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'empresa_id' => $empresaId,
                'producto_id' => $productoId,
                'materia_prima_id' => $grapasId,
                'cantidad_por_unidad' => 15,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // 6. Temporada y Metas
        $temporadaId = DB::table('temporadas_madera')->insertGetId([
            'empresa_id' => $empresaId,
            'nombre' => 'Temporada Día de las Madres',
            'fecha_inicio' => now(),
            'fecha_fin' => now()->addMonths(2),
            'activa' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('maderas_metas_temporada')->insert([
            'empresa_id' => $empresaId,
            'temporada_id' => $temporadaId,
            'producto_id' => $productoId,
            'meta_piezas' => 240,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 7. Bastones
        DB::table('bastones_madera')->insert([
            'empresa_id' => $empresaId,
            'nombre' => 'Bastón 3 pisos',
            'pisos' => 3,
            'stock' => 500,
            'alerta_minimo' => 50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

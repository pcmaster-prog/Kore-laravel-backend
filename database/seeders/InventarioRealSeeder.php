<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InventarioRealSeeder extends Seeder
{
    /**
     * Carga el inventario REAL de DecorArte a partir de la foto del 17/06/2026
     * y los datos del Excel de Pino Gordo.
     * 
     * Ejecutar: php artisan db:seed --class=InventarioRealSeeder
     */
    public function run(): void
    {
        $empresaId = 1; // Ajusta a tu empresa_id real

        $this->command->info('Reseteando stocks a cero...');

        // 1. Resetear stocks
        DB::table('maderas_materias_primas')
            ->where('tipo', 'like', 'hoja_mdf%')
            ->update(['stock_actual' => 0]);
        DB::table('maderas_productos')->update(['stock_armadas' => 0, 'stock_bolsas' => 0]);
        DB::table('bastones_madera')->update(['stock_actual' => 0]);

        $this->command->info('Cargando stock de hojas MDF...');

        // 2. HOJAS MDF — Stock REAL (datos del Excel 20/03/2026)
        $hojasStock = [
            // Rectangulares
            ['tipo' => 'hoja_mdf_rectangular', 'dimension' => '40x60',  'stock' => 88],
            ['tipo' => 'hoja_mdf_rectangular', 'dimension' => '40x50',  'stock' => 119],
            ['tipo' => 'hoja_mdf_rectangular', 'dimension' => '50x70',  'stock' => 4],
            ['tipo' => 'hoja_mdf_rectangular', 'dimension' => '60x90',  'stock' => 0],
            ['tipo' => 'hoja_mdf_rectangular', 'dimension' => '70x100', 'stock' => 0],
            // Circulares
            ['tipo' => 'hoja_mdf_circular',    'dimension' => '25cm',   'stock' => 106],
            ['tipo' => 'hoja_mdf_circular',    'dimension' => '30cm',   'stock' => 760],
            ['tipo' => 'hoja_mdf_circular',    'dimension' => '35cm',   'stock' => 180],
            ['tipo' => 'hoja_mdf_circular',    'dimension' => '40cm',   'stock' => 62],
            ['tipo' => 'hoja_mdf_circular',    'dimension' => '45cm',   'stock' => 16],
            ['tipo' => 'hoja_mdf_circular',    'dimension' => '50cm',   'stock' => 0],
            ['tipo' => 'hoja_mdf_circular',    'dimension' => '55cm',   'stock' => 94],
            ['tipo' => 'hoja_mdf_circular',    'dimension' => '60cm',   'stock' => 56],
            ['tipo' => 'hoja_mdf_circular',    'dimension' => '65cm',   'stock' => 30],
            ['tipo' => 'hoja_mdf_circular',    'dimension' => '70cm',   'stock' => 6],
            // Cuadradas
            ['tipo' => 'hoja_mdf_cuadrada',    'dimension' => '40x40',  'stock' => 40],
            ['tipo' => 'hoja_mdf_cuadrada',    'dimension' => '45x45',  'stock' => 88],
            ['tipo' => 'hoja_mdf_cuadrada',    'dimension' => '60x60',  'stock' => 0],
        ];

        foreach ($hojasStock as $h) {
            $updated = DB::table('maderas_materias_primas')
                ->where('empresa_id', $empresaId)
                ->where('tipo', $h['tipo'])
                ->where('dimension', $h['dimension'])
                ->update(['stock_actual' => $h['stock']]);

            if ($updated === 0) {
                $this->command->warn("  No se encontro: {$h['tipo']} {$h['dimension']} — verifica el seeder base");
            }
        }

        $this->command->info('Insertando productos nuevos del inventario...');

        // 3. PRODUCTOS NUEVOS que aparecen en la foto pero no en el seeder base
        $productosNuevos = [
            ['nombre' => 'Rectangular 50x60 1 piso',  'categoria' => 'rectangular', 'dimension' => '50x60', 'pisos' => 1, 'tabla_corte_id' => null],
            ['nombre' => 'Cuadrada 50x50 1 piso',     'categoria' => 'cuadrada',    'dimension' => '50x50', 'pisos' => 1, 'tabla_corte_id' => null],
            ['nombre' => 'Circular 40cm 1 piso',      'categoria' => 'redonda',     'dimension' => '40cm',  'pisos' => 1, 'tabla_corte_id' => null],
        ];

        foreach ($productosNuevos as $p) {
            $exists = DB::table('maderas_productos')
                ->where('empresa_id', $empresaId)
                ->where('dimension', $p['dimension'])
                ->where('pisos', $p['pisos'])
                ->where('categoria', $p['categoria'])
                ->exists();

            if (!$exists) {
                $p['empresa_id'] = $empresaId;
                $p['stock_armadas'] = 0;
                $p['stock_bolsas'] = 0;
                $p['created_at'] = now();
                $p['updated_at'] = now();
                DB::table('maderas_productos')->insert($p);
                $this->command->info("  Creado: {$p['nombre']}");
            }
        }

        $this->command->info('Cargando stock de bases armadas (datos reales de la foto)...');

        // 4. BASES ARMADAS — Datos EXACTOS de la foto del inventario
        $basesArmadas = [
            // Circulares
            ['categoria' => 'redonda',     'dimension' => '30cm',  'pisos' => 1, 'stock' => 15],
            ['categoria' => 'redonda',     'dimension' => '45cm',  'pisos' => 1, 'stock' => 23],
            ['categoria' => 'redonda',     'dimension' => '40cm',  'pisos' => 1, 'stock' => 9],
            // Rectangulares
            ['categoria' => 'rectangular', 'dimension' => '40x50', 'pisos' => 1, 'stock' => 59],
            ['categoria' => 'rectangular', 'dimension' => '40x60', 'pisos' => 1, 'stock' => 7],
            ['categoria' => 'rectangular', 'dimension' => '50x60', 'pisos' => 1, 'stock' => 30],
            // Cuadradas
            ['categoria' => 'cuadrada',    'dimension' => '50x50', 'pisos' => 1, 'stock' => 11],
            ['categoria' => 'cuadrada',    'dimension' => '45x45', 'pisos' => 1, 'stock' => 20],
        ];

        foreach ($basesArmadas as $b) {
            $updated = DB::table('maderas_productos')
                ->where('empresa_id', $empresaId)
                ->where('categoria', $b['categoria'])
                ->where('dimension', $b['dimension'])
                ->where('pisos', $b['pisos'])
                ->update(['stock_armadas' => $b['stock']]);

            if ($updated > 0) {
                $this->command->info("  {$b['dimension']} ({$b['categoria']}): {$b['stock']} armadas");
            } else {
                $this->command->warn("  NO ENCONTRADO: {$b['dimension']} {$b['categoria']} — debe crearse primero");
            }
        }

        $this->command->info('Cargando stock de bastones...');

        // 5. BASTONES
        // Wait! The table is bastones_madera, does it have 'tipo'?
        // The migration says: 'nombre', 'pisos', 'stock', 'alerta_minimo'. No 'tipo'!
        // So we need to query by 'pisos'.
        DB::table('bastones_madera')->where('empresa_id', $empresaId)->where('pisos', 2)->update(['stock' => 66]);
        DB::table('bastones_madera')->where('empresa_id', $empresaId)->where('pisos', 3)->update(['stock' => 77]);
        DB::table('bastones_madera')->where('empresa_id', $empresaId)->where('pisos', 4)->update(['stock' => 291]);

        $this->command->info('Cargando consumibles...');

        // 6. CONSUMIBLES
        DB::table('maderas_materias_primas')
            ->where('empresa_id', $empresaId)
            ->where('tipo', 'consumible')
            ->where('nombre', 'like', '%Grapas%')
            ->update(['stock_actual' => 10]);
        DB::table('maderas_materias_primas')
            ->where('empresa_id', $empresaId)
            ->where('tipo', 'consumible')
            ->where('nombre', 'like', '%Resistol%')
            ->update(['stock_actual' => 2]);
        DB::table('maderas_materias_primas')
            ->where('empresa_id', $empresaId)
            ->where('tipo', 'consumible')
            ->where('nombre', 'like', '%Engrapadora%')
            ->update(['stock_actual' => 1]);

        $this->command->info('Reseteando palos a 0...');

        // 7. PALOS (sin stock por ahora)
        DB::table('maderas_materias_primas')
            ->where('empresa_id', $empresaId)
            ->where('tipo', 'palo')
            ->update(['stock_actual' => 0]);


        // 8. CREACIÓN DE PEDIDO MANUAL PARA PINO GORDO
        $this->command->info('Generando pedido manual para Pino Gordo...');

        $pedidoId = DB::table('pedidos_madera')->insertGetId([
            'empresa_id' => $empresaId,
            'codigo' => 'PED-MANUAL-' . strtoupper(Str::random(5)),
            'status' => 'pendiente',
            'total' => 0, // Se actualizará si hay precios
            'fecha_pedido' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $itemsPedido = [
            ['seccion_pdf' => 'hojas_mdf', 'nombre' => 'Hoja MDF Circular 25cm', 'cantidad' => 120, 'precio' => 0],
            ['seccion_pdf' => 'hojas_mdf', 'nombre' => 'Hoja MDF Circular 35cm', 'cantidad' => 150, 'precio' => 0],
            ['seccion_pdf' => 'hojas_mdf', 'nombre' => 'Hoja MDF Circular 40cm', 'cantidad' => 100, 'precio' => 0],
            ['seccion_pdf' => 'hojas_mdf', 'nombre' => 'Hoja MDF Circular 45cm', 'cantidad' => 130, 'precio' => 0],
            ['seccion_pdf' => 'hojas_mdf', 'nombre' => 'Hoja MDF Circular 50cm', 'cantidad' => 50, 'precio' => 0],
            ['seccion_pdf' => 'hojas_mdf', 'nombre' => 'Hoja MDF Rectangular 40x50', 'cantidad' => 120, 'precio' => 0],
            ['seccion_pdf' => 'hojas_mdf', 'nombre' => 'Hoja MDF Rectangular 40x60', 'cantidad' => 160, 'precio' => 0],
            ['seccion_pdf' => 'hojas_mdf', 'nombre' => 'Hoja MDF Rectangular 50x70', 'cantidad' => 100, 'precio' => 0],
            ['seccion_pdf' => 'hojas_mdf', 'nombre' => 'Hoja MDF Cuadrada 40x40', 'cantidad' => 50, 'precio' => 0],
            ['seccion_pdf' => 'consumibles', 'nombre' => 'Engrapadora', 'cantidad' => 1, 'precio' => 0],
            ['seccion_pdf' => 'consumibles', 'nombre' => 'Galones Resistol', 'cantidad' => 2, 'precio' => 0],
            ['seccion_pdf' => 'consumibles', 'nombre' => 'Palos Extra', 'cantidad' => 26, 'precio' => 0],
        ];

        $detallesPedido = [];
        foreach ($itemsPedido as $ip) {
            $detallesPedido[] = [
                'empresa_id' => $empresaId,
                'pedido_id' => $pedidoId,
                'seccion_pdf' => $ip['seccion_pdf'],
                'item_id' => null,
                'nombre_item' => $ip['nombre'],
                'cantidad' => $ip['cantidad'],
                'precio_unitario' => $ip['precio'],
                'subtotal' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('detalle_pedido_madera')->insert($detallesPedido);

        // ──────────────────────────────────────────────────────────
        // RESUMEN FINAL
        // ──────────────────────────────────────────────────────────
        $this->command->newLine();
        $this->command->info('=== INVENTARIO CARGADO ===');

        $totalBases = DB::table('maderas_productos')
            ->where('empresa_id', $empresaId)
            ->sum('stock_armadas');
        $this->command->info("Total bases armadas: {$totalBases}");

        $totalHojas = DB::table('maderas_materias_primas')
            ->where('empresa_id', $empresaId)
            ->where('tipo', 'like', 'hoja_mdf%')
            ->sum('stock_actual');
        $this->command->info("Total hojas MDF en stock: {$totalHojas}");

        $totalBastones = DB::table('bastones_madera')
            ->where('empresa_id', $empresaId)
            ->sum('stock');
        $this->command->info("Total bastones: {$totalBastones}");

        $this->command->info("Se ha generado un Pedido Manual en el sistema con el ID: {$pedidoId}");

        $this->command->newLine();
        $this->command->warn('NOTA: Los stocks de hojas MDF son del Excel 20/03/2026.');
        $this->command->warn('Si tu stock fisico actual es diferente, ajustalo desde el frontend');
        $this->command->warn('o modifica los valores en este seeder y vuelve a ejecutar.');
    }
}

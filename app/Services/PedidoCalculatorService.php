<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class PedidoCalculatorService
{
    public function calculatePedido($temporadaId, $empresaId)
    {
        // 1. Obtener los productos con sus metas en la temporada
        $metas = DB::table('maderas_metas_temporada as mmt')
            ->join('maderas_productos as mp', 'mmt.producto_id', '=', 'mp.id')
            ->where('mmt.temporada_id', $temporadaId)
            ->where('mmt.empresa_id', $empresaId)
            ->select('mmt.meta_piezas', 'mp.id', 'mp.stock_bolsas', 'mp.stock_armadas', 'mp.categoria', 'mp.tabla_corte_id')
            ->get();

        $pedidoData = [
            'hojas_mdf' => [],
            'tablas_pino' => [],
            'consumibles' => [],
            'servicios_corte' => [],
            'total_pedido' => 0,
        ];

        $hojasMdfCountRectangular = 0;
        $hojasMdfCountCircular = 0;
        $tablasPinoCount = 0;

        foreach ($metas as $meta) {
            $faltantes = $meta->meta_piezas - $meta->stock_bolsas - $meta->stock_armadas;
            if ($faltantes <= 0) {
                continue;
            }

            // Obtener recetas de este producto
            $recetas = DB::table('maderas_recetas as mr')
                ->join('maderas_materias_primas as mmp', 'mr.materia_prima_id', '=', 'mmp.id')
                ->where('mr.producto_id', $meta->id)
                ->where('mr.empresa_id', $empresaId)
                ->select('mmp.id', 'mmp.nombre', 'mmp.tipo', 'mmp.pxh', 'mmp.pxt', 'mmp.stock_actual', 'mmp.precio_unitario', 'mr.cantidad_por_unidad')
                ->get();

            foreach ($recetas as $receta) {
                if (str_starts_with($receta->tipo, 'hoja_mdf')) {
                    $hojasRequeridas = ceil($faltantes / max(1, $receta->pxh));
                    $hojasAPedir = max(0, $hojasRequeridas - $receta->stock_actual);

                    if ($hojasAPedir > 0) {
                        $subtotal = $hojasAPedir * $receta->precio_unitario;
                        $pedidoData['hojas_mdf'][] = [
                            'item_id' => $receta->id,
                            'nombre' => "Hojas para {$meta->categoria} (Faltan $faltantes piezas) - {$receta->nombre}",
                            'cantidad' => $hojasAPedir,
                            'precio_unitario' => $receta->precio_unitario,
                            'subtotal' => $subtotal,
                        ];
                        $pedidoData['total_pedido'] += $subtotal;

                        if ($meta->categoria === 'rectangular') {
                            $hojasMdfCountRectangular += $hojasAPedir;
                        } elseif ($meta->categoria === 'redonda') {
                            $hojasMdfCountCircular += $hojasAPedir;
                        }
                    }
                } elseif ($receta->tipo === 'consumible') {
                    $consumibleRequerido = $faltantes * $receta->cantidad_por_unidad;
                    $consumibleAPedir = max(0, $consumibleRequerido - $receta->stock_actual);

                    if ($consumibleAPedir > 0) {
                        $subtotal = $consumibleAPedir * $receta->precio_unitario;
                        $pedidoData['consumibles'][] = [
                            'item_id' => $receta->id,
                            'nombre' => "{$receta->nombre} (Faltan $faltantes piezas)",
                            'cantidad' => $consumibleAPedir,
                            'precio_unitario' => $receta->precio_unitario,
                            'subtotal' => $subtotal,
                        ];
                        $pedidoData['total_pedido'] += $subtotal;
                    }
                }
            }

            // Calcular Tablas de Pino (si el producto usa tabla)
            if ($meta->tabla_corte_id) {
                $tabla = DB::table('maderas_tabla_cortes')->where('id', $meta->tabla_corte_id)->first();
                if ($tabla) {
                    // tablas_requeridas es decimal exacto, se puede pedir 1 tabla entera
                    $tablasRequeridas = ceil($faltantes / max(1, $tabla->pxt));
                    if ($tablasRequeridas > 0) {
                        $subtotal = $tablasRequeridas * $tabla->precio_unitario_tabla;
                        $pedidoData['tablas_pino'][] = [
                            'item_id' => $tabla->id,
                            'nombre' => "Tabla de {$tabla->medida_cm}cm (Faltan $faltantes piezas)",
                            'cantidad' => $tablasRequeridas,
                            'precio_unitario' => $tabla->precio_unitario_tabla,
                            'subtotal' => $subtotal,
                        ];
                        $pedidoData['total_pedido'] += $subtotal;
                        $tablasPinoCount += $tablasRequeridas;
                    }
                }
            }
        }

        // Servicios de Corte
        $servicios = DB::table('maderas_servicios_corte')->where('empresa_id', $empresaId)->get()->keyBy('slug');

        if ($hojasMdfCountRectangular > 0 && isset($servicios['recortes_rectangulares'])) {
            $srv = $servicios['recortes_rectangulares'];
            $sub = $hojasMdfCountRectangular * $srv->precio_fijo;
            $pedidoData['servicios_corte'][] = [
                'item_id' => $srv->id,
                'nombre' => "{$srv->nombre} ({$hojasMdfCountRectangular} hojas)",
                'cantidad' => $hojasMdfCountRectangular,
                'precio_unitario' => $srv->precio_fijo,
                'subtotal' => $sub,
            ];
            $pedidoData['total_pedido'] += $sub;
        }

        if ($hojasMdfCountCircular > 0 && isset($servicios['recortes_circulos'])) {
            $srv = $servicios['recortes_circulos'];
            $sub = $hojasMdfCountCircular * $srv->precio_fijo;
            $pedidoData['servicios_corte'][] = [
                'item_id' => $srv->id,
                'nombre' => "{$srv->nombre} ({$hojasMdfCountCircular} hojas)",
                'cantidad' => $hojasMdfCountCircular,
                'precio_unitario' => $srv->precio_fijo,
                'subtotal' => $sub,
            ];
            $pedidoData['total_pedido'] += $sub;
        }

        if ($tablasPinoCount > 0 && isset($servicios['tablas_en_tiras'])) {
            $srv = $servicios['tablas_en_tiras'];
            $sub = $tablasPinoCount * $srv->precio_fijo;
            $pedidoData['servicios_corte'][] = [
                'item_id' => $srv->id,
                'nombre' => "{$srv->nombre} ({$tablasPinoCount} tablas)",
                'cantidad' => $tablasPinoCount,
                'precio_unitario' => $srv->precio_fijo,
                'subtotal' => $sub,
            ];
            $pedidoData['total_pedido'] += $sub;
        }

        return $pedidoData;
    }
}

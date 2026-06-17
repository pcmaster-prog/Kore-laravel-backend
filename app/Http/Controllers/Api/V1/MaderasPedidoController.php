<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\PedidoCalculatorService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;

class MaderasPedidoController extends Controller
{
    protected $calculator;

    public function __construct(PedidoCalculatorService $calculator)
    {
        $this->calculator = $calculator;
    }

    public function calcular(Request $request)
    {
        $request->validate([
            'temporada_id' => 'required|integer'
        ]);

        $empresaId = 1; // Ajustar con auth()->user()->empresa_id en prod

        $pedidoData = $this->calculator->calculatePedido($request->temporada_id, $empresaId);

        return response()->json([
            'data' => $pedidoData
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'temporada_id' => 'nullable|integer',
            'cliente' => 'nullable|string|max:255',
            'items' => 'nullable|array',
            'fecha_entrega' => 'nullable|date',
        ]);

        $empresaId = 1;

        if ($request->has('temporada_id') && $request->temporada_id) {
            // MODO AUTOMÁTICO
            $pedidoData = $this->calculator->calculatePedido($request->temporada_id, $empresaId);

            if ($pedidoData['total_pedido'] <= 0) {
                return response()->json(['message' => 'No hay faltantes para generar pedido.'], 400);
            }

            DB::beginTransaction();
            try {
                $codigo = 'PED-AUTO-' . strtoupper(Str::random(5));

                $pedidoId = DB::table('pedidos_madera')->insertGetId([
                    'empresa_id' => $empresaId,
                    'codigo' => $codigo,
                    'status' => 'pendiente',
                    'total' => $pedidoData['total_pedido'],
                    'fecha_pedido' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $detalles = [];
                $secciones = ['hojas_mdf', 'tablas_pino', 'consumibles', 'servicios_corte'];

                foreach ($secciones as $seccion) {
                    if (!isset($pedidoData[$seccion])) continue;

                    foreach ($pedidoData[$seccion] as $item) {
                        $detalles[] = [
                            'empresa_id' => $empresaId,
                            'pedido_id' => $pedidoId,
                            'seccion_pdf' => $seccion,
                            'item_id' => $item['item_id'],
                            'nombre_item' => $item['nombre'],
                            'cantidad' => $item['cantidad'],
                            'precio_unitario' => $item['precio_unitario'],
                            'subtotal' => $item['subtotal'],
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }
                }

                DB::table('detalle_pedido_madera')->insert($detalles);

                DB::commit();

                return response()->json([
                    'message' => 'Pedido generado automáticamente',
                    'pedido_id' => $pedidoId,
                    'codigo' => $codigo
                ], 201);
            } catch (\Exception $e) {
                DB::rollBack();
                return response()->json(['message' => 'Error al generar pedido: ' . $e->getMessage()], 500);
            }
        } else {
            // MODO MANUAL
            $request->validate([
                'codigo' => 'required|string',
                'cliente' => 'required|string',
                'items' => 'required|array',
            ]);

            DB::beginTransaction();
            try {
                $totalPedido = array_reduce($request->items, function($carry, $item) {
                    return $carry + ($item['total'] ?? ($item['cantidad'] * $item['precio_unitario']));
                }, 0);

                $pedidoId = DB::table('pedidos_madera')->insertGetId([
                    'empresa_id' => $empresaId,
                    'codigo' => $request->codigo,
                    'status' => 'pendiente',
                    'total' => $totalPedido,
                    'fecha_pedido' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $detalles = [];
                foreach ($request->items as $item) {
                    // Mapeamos las categorias manuales a la seccion_pdf que se espera, o se pone un default
                    $seccionPdf = 'hojas_mdf';
                    if (str_contains(strtolower($item['categoria']), 'tabla')) $seccionPdf = 'tablas_pino';
                    if (str_contains(strtolower($item['categoria']), 'consumible')) $seccionPdf = 'consumibles';
                    if (str_contains(strtolower($item['categoria']), 'corte')) $seccionPdf = 'servicios_corte';

                    $detalles[] = [
                        'empresa_id' => $empresaId,
                        'pedido_id' => $pedidoId,
                        'seccion_pdf' => $seccionPdf,
                        'item_id' => null,
                        'nombre_item' => $item['descripcion'] ?? $item['categoria'],
                        'cantidad' => $item['cantidad'],
                        'precio_unitario' => $item['precio_unitario'],
                        'subtotal' => $item['total'] ?? ($item['cantidad'] * $item['precio_unitario']),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                DB::table('detalle_pedido_madera')->insert($detalles);

                DB::commit();

                return response()->json([
                    'message' => 'Pedido manual creado',
                    'pedido_id' => $pedidoId,
                    'codigo' => $request->codigo
                ], 201);
            } catch (\Exception $e) {
                DB::rollBack();
                return response()->json(['message' => 'Error al guardar pedido manual: ' . $e->getMessage()], 500);
            }
        }
    }

    public function pdf($id)
    {
        $pedido = DB::table('pedidos_madera')->where('id', $id)->first();
        if (!$pedido) {
            return response()->json(['message' => 'Pedido no encontrado'], 404);
        }

        $detalles = DB::table('detalle_pedido_madera')->where('pedido_id', $id)->get();

        $pdf = Pdf::loadView('pedidos.pino_gordo', compact('pedido', 'detalles'));

        return $pdf->download("Pedido_{$pedido->codigo}.pdf");
    }

    public function index()
    {
        $pedidos = DB::table('pedidos_madera')->orderBy('created_at', 'desc')->get();
        return response()->json($pedidos);
    }

    public function show(string $id)
    {
        $pedido = DB::table('pedidos_madera')->where('id', $id)->first();
        if (!$pedido) return response()->json(['message' => 'Not found'], 404);

        $detalles = DB::table('detalle_pedido_madera')->where('pedido_id', $id)->get();
        $pedido->detalles = $detalles;

        return response()->json($pedido);
    }

    public function update(Request $request, string $id)
    {
        $pedido = DB::table('pedidos_madera')->where('id', $id)->first();
        if (!$pedido) return response()->json(['message' => 'Not found'], 404);

        if ($request->has('status')) {
            DB::table('pedidos_madera')
                ->where('id', $id)
                ->update([
                    'status' => $request->status,
                    'updated_at' => now()
                ]);
        }

        return response()->json(['message' => 'Pedido actualizado exitosamente']);
    }

    public function destroy(string $id)
    {
        DB::table('pedidos_madera')->where('id', $id)->delete();
        return response()->json(null, 204);
    }
}

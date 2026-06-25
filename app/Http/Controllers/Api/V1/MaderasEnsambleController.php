<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MaderasEnsamble;
use App\Models\MaderasEnsamblePieza;
use App\Models\MaderasInventario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MaderasEnsambleController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        // $ensambles = MaderasEnsamble::with(['catalogo', 'piezas.catalogo'])->orderBy('created_at', 'desc')->get();
        // return response()->json($ensambles);
        return response()->json([]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'catalogo_id' => 'required|exists:maderas_catalogos,id',
            'cantidad_generada' => 'required|integer|min:1',
            'piezas' => 'required|array|min:1',
            'piezas.*.catalogo_id' => 'required|exists:maderas_catalogos,id',
            'piezas.*.cantidad_usada' => 'required|integer|min:1',
        ]);

        try {
            DB::beginTransaction();

            $ensamble = MaderasEnsamble::create([
                'catalogo_id' => $validated['catalogo_id'],
                'cantidad_generada' => $validated['cantidad_generada'],
                'status' => 'en_proceso',
            ]);

            foreach ($validated['piezas'] as $pieza) {
                MaderasEnsamblePieza::create([
                    'ensamble_id' => $ensamble->id,
                    'catalogo_id' => $pieza['catalogo_id'],
                    'cantidad_usada' => $pieza['cantidad_usada'],
                ]);

                // Descontar piezas del inventario
                $invPieza = MaderasInventario::where('catalogo_id', $pieza['catalogo_id'])->first();
                if ($invPieza) {
                    $newStock = max(0, $invPieza->stock - $pieza['cantidad_usada']);
                    $status = 'ok';
                    if ($newStock <= $invPieza->stock_minimo) {
                        $status = $newStock == 0 ? 'critical' : 'low';
                    }
                    $invPieza->update([
                        'stock' => $newStock,
                        'status' => $status,
                    ]);
                }
            }

            DB::commit();

            return response()->json($ensamble->load(['catalogo', 'piezas.catalogo']), 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['error' => 'Error al registrar ensamble: '.$e->getMessage()], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $ensamble = MaderasEnsamble::with(['catalogo', 'piezas.catalogo'])->findOrFail($id);

        return response()->json($ensamble);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $ensamble = MaderasEnsamble::findOrFail($id);

        $validated = $request->validate([
            'status' => 'required|in:en_proceso,listo',
        ]);

        try {
            DB::beginTransaction();

            if ($ensamble->status === 'en_proceso' && $validated['status'] === 'listo') {
                // Sumar al inventario el producto terminado
                $inventario = MaderasInventario::firstOrCreate(
                    ['catalogo_id' => $ensamble->catalogo_id],
                    ['stock' => 0, 'stock_minimo' => 0, 'status' => 'critical']
                );

                $newStock = $inventario->stock + $ensamble->cantidad_generada;
                $status = 'ok';
                if ($newStock <= $inventario->stock_minimo) {
                    $status = $newStock == 0 ? 'critical' : 'low';
                }

                $inventario->update([
                    'stock' => $newStock,
                    'status' => $status,
                ]);
            }

            $ensamble->update(['status' => $validated['status']]);

            DB::commit();

            return response()->json($ensamble->load(['catalogo', 'piezas.catalogo']));
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['error' => 'Error al actualizar ensamble: '.$e->getMessage()], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $ensamble = MaderasEnsamble::findOrFail($id);

        // La anulación de ensambles completos también debería regresar los materiales al inventario
        // Para simplificar, permitimos borrar en cascada pero no revertimos el inventario aquí (idealmente no se permite delete o se hace un soft_delete)
        return response()->json(['error' => 'No soportado. Considerar anular vía ajuste de inventario'], 400);
    }
}

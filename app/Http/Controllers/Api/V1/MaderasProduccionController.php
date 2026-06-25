<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MaderasInventario;
use App\Models\MaderasProduccion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MaderasProduccionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        // $produccion = MaderasProduccion::with(['empleado.user', 'catalogo'])->orderBy('fecha_registro', 'desc')->get();
        // return response()->json($produccion);
        return response()->json([]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'empleado_id' => 'required|exists:empleados,id',
            'catalogo_id' => 'required|exists:maderas_catalogos,id',
            'maquina' => 'nullable|string|max:100',
            'cantidad' => 'required|integer|min:1',
        ]);

        try {
            DB::beginTransaction();

            $produccion = MaderasProduccion::create([
                'empleado_id' => $validated['empleado_id'],
                'catalogo_id' => $validated['catalogo_id'],
                'maquina' => $validated['maquina'],
                'cantidad' => $validated['cantidad'],
                'fecha_registro' => now(),
            ]);

            // Update inventory
            $inventario = MaderasInventario::firstOrCreate(
                ['catalogo_id' => $validated['catalogo_id']],
                ['stock' => 0, 'stock_minimo' => 0, 'status' => 'critical']
            );

            $newStock = $inventario->stock + $validated['cantidad'];
            $status = 'ok';
            if ($newStock <= $inventario->stock_minimo) {
                $status = $newStock == 0 ? 'critical' : 'low';
            }

            $inventario->update([
                'stock' => $newStock,
                'status' => $status,
            ]);

            DB::commit();

            return response()->json($produccion->load(['empleado', 'catalogo']), 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['error' => 'Error al registrar producción: '.$e->getMessage()], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $produccion = MaderasProduccion::with(['empleado', 'catalogo'])->findOrFail($id);

        return response()->json($produccion);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        // Generalmente la producción no se actualiza (es un log), pero si se corrige,
        // requeriría un ajuste en inventario también. Por ahora retornamos error.
        return response()->json(['error' => 'No soportado directamente. Eliminar y recrear o usar ajuste de inventario.'], 400);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $produccion = MaderasProduccion::findOrFail($id);

        try {
            DB::beginTransaction();

            // Reverse inventory
            $inventario = MaderasInventario::where('catalogo_id', $produccion->catalogo_id)->first();
            if ($inventario) {
                $newStock = max(0, $inventario->stock - $produccion->cantidad);
                $status = 'ok';
                if ($newStock <= $inventario->stock_minimo) {
                    $status = $newStock == 0 ? 'critical' : 'low';
                }
                $inventario->update([
                    'stock' => $newStock,
                    'status' => $status,
                ]);
            }

            $produccion->delete();

            DB::commit();

            return response()->json(null, 204);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['error' => 'Error al anular producción: '.$e->getMessage()], 500);
        }
    }
}

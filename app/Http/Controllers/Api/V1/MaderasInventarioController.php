<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MaderasInventario;
use Illuminate\Http\Request;

class MaderasInventarioController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        // $inventario = MaderasInventario::with('catalogo')->get();
        // return response()->json($inventario);
        return response()->json([]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'catalogo_id' => 'required|exists:maderas_catalogos,id',
            'stock' => 'required|integer|min:0',
            'stock_minimo' => 'integer|min:0',
        ]);

        $status = 'ok';
        if ($validated['stock'] <= $request->input('stock_minimo', 0)) {
            $status = $validated['stock'] == 0 ? 'critical' : 'low';
        }

        $inventario = MaderasInventario::create([
            'catalogo_id' => $validated['catalogo_id'],
            'stock' => $validated['stock'],
            'stock_minimo' => $validated['stock_minimo'] ?? 0,
            'status' => $status,
        ]);

        return response()->json($inventario->load('catalogo'), 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $inventario = MaderasInventario::with('catalogo')->findOrFail($id);
        return response()->json($inventario);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $inventario = MaderasInventario::findOrFail($id);

        $validated = $request->validate([
            'stock' => 'sometimes|required|integer|min:0',
            'stock_minimo' => 'sometimes|required|integer|min:0',
        ]);

        $stock = $request->has('stock') ? $validated['stock'] : $inventario->stock;
        $minimo = $request->has('stock_minimo') ? $validated['stock_minimo'] : $inventario->stock_minimo;

        $status = 'ok';
        if ($stock <= $minimo) {
            $status = $stock == 0 ? 'critical' : 'low';
        }

        $inventario->update([
            'stock' => $stock,
            'stock_minimo' => $minimo,
            'status' => $status,
        ]);

        return response()->json($inventario->load('catalogo'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $inventario = MaderasInventario::findOrFail($id);
        $inventario->delete();

        return response()->json(null, 204);
    }
}

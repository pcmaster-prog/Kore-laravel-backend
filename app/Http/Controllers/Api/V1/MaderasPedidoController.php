<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MaderasPedido;
use Illuminate\Http\Request;

class MaderasPedidoController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $pedidos = MaderasPedido::orderBy('created_at', 'desc')->get();
        return response()->json($pedidos);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'codigo' => 'required|string|unique:maderas_pedidos,codigo|max:100',
            'cliente' => 'required|string|max:255',
            'total_unidades' => 'required|integer|min:1',
            'fecha_entrega' => 'nullable|date',
        ]);

        $pedido = MaderasPedido::create([
            'codigo' => $validated['codigo'],
            'cliente' => $validated['cliente'],
            'total_unidades' => $validated['total_unidades'],
            'status' => 'pendiente',
            'fecha_entrega' => $validated['fecha_entrega'] ?? null,
        ]);

        return response()->json($pedido, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $pedido = MaderasPedido::findOrFail($id);
        return response()->json($pedido);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $pedido = MaderasPedido::findOrFail($id);

        $validated = $request->validate([
            'cliente' => 'sometimes|required|string|max:255',
            'total_unidades' => 'sometimes|required|integer|min:1',
            'status' => 'sometimes|required|in:pendiente,entregado',
            'fecha_entrega' => 'nullable|date',
        ]);

        $pedido->update($validated);

        return response()->json($pedido);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $pedido = MaderasPedido::findOrFail($id);
        $pedido->delete();

        return response()->json(null, 204);
    }
}

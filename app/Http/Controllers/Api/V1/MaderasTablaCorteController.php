<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MaderasTablaCorte;
use Illuminate\Http\Request;

class MaderasTablaCorteController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $tablas = MaderasTablaCorte::orderBy('nombre')->get();
        return response()->json(['data' => $tablas]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'nombre' => 'required|string|max:255',
            'rendimiento_esperado' => 'numeric|min:0.1|max:100.0',
        ]);

        $tabla = MaderasTablaCorte::create([
            'nombre' => $validated['nombre'],
            'rendimiento_esperado' => $validated['rendimiento_esperado'] ?? 1.0,
        ]);

        return response()->json(['data' => $tabla], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $tabla = MaderasTablaCorte::findOrFail($id);
        return response()->json(['data' => $tabla]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $tabla = MaderasTablaCorte::findOrFail($id);

        $validated = $request->validate([
            'nombre' => 'sometimes|required|string|max:255',
            'rendimiento_esperado' => 'sometimes|numeric|min:0.1|max:100.0',
        ]);

        $tabla->update($validated);

        return response()->json(['data' => $tabla]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $tabla = MaderasTablaCorte::findOrFail($id);
        $tabla->delete();

        return response()->json(null, 204);
    }
}

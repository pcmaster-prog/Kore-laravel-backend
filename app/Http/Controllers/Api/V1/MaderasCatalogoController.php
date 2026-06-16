<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MaderasCatalogo;
use Illuminate\Http\Request;

class MaderasCatalogoController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $catalogos = MaderasCatalogo::all();
        return response()->json($catalogos);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'nombre' => 'required|string|max:255',
            'tipo' => 'required|in:baston,producto_terminado,insumo',
            'unidad_medida' => 'nullable|string|max:50',
        ]);

        $catalogo = MaderasCatalogo::create([
            'nombre' => $validated['nombre'],
            'tipo' => $validated['tipo'],
            'unidad_medida' => $validated['unidad_medida'] ?? 'uds',
        ]);

        return response()->json($catalogo, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $catalogo = MaderasCatalogo::findOrFail($id);
        return response()->json($catalogo);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $catalogo = MaderasCatalogo::findOrFail($id);

        $validated = $request->validate([
            'nombre' => 'sometimes|required|string|max:255',
            'tipo' => 'sometimes|required|in:baston,producto_terminado,insumo',
            'unidad_medida' => 'nullable|string|max:50',
        ]);

        $catalogo->update($validated);

        return response()->json($catalogo);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $catalogo = MaderasCatalogo::findOrFail($id);
        $catalogo->delete();

        return response()->json(null, 204);
    }
}

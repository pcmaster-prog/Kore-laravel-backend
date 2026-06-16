<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MaderasTemporada;
use Illuminate\Http\Request;

class MaderasTemporadaController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $temporadas = MaderasTemporada::all();
        return response()->json($temporadas);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'nombre' => 'required|string|max:255',
            'mes_inicio' => 'required|integer|min:1|max:12',
            'mes_fin' => 'required|integer|min:1|max:12',
            'multiplicador' => 'numeric|min:0.1|max:10.0',
        ]);

        $temporada = MaderasTemporada::create([
            'nombre' => $validated['nombre'],
            'mes_inicio' => $validated['mes_inicio'],
            'mes_fin' => $validated['mes_fin'],
            'multiplicador' => $validated['multiplicador'] ?? 1.0,
        ]);

        return response()->json($temporada, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $temporada = MaderasTemporada::findOrFail($id);
        return response()->json($temporada);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $temporada = MaderasTemporada::findOrFail($id);

        $validated = $request->validate([
            'nombre' => 'sometimes|required|string|max:255',
            'mes_inicio' => 'sometimes|required|integer|min:1|max:12',
            'mes_fin' => 'sometimes|required|integer|min:1|max:12',
            'multiplicador' => 'sometimes|numeric|min:0.1|max:10.0',
        ]);

        $temporada->update($validated);

        return response()->json($temporada);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $temporada = MaderasTemporada::findOrFail($id);
        $temporada->delete();

        return response()->json(null, 204);
    }
}

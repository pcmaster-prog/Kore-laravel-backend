<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MaderasCatalogo;
use App\Models\MaderasProduccion;
use App\Models\MaderasPedido;
use App\Models\MaderasInventario;
use App\Models\MaderasEnsamble;
use Illuminate\Http\Request;

class MaderasCatalogoController extends Controller
{
    public function index()
    {
        return response()->json([
            'data' => MaderasCatalogo::orderBy('nombre')->get()
        ]);
    }

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

        // Automatically create inventory record for this catalog item if it doesn't exist
        MaderasInventario::firstOrCreate(
            ['catalogo_id' => $catalogo->id],
            [
                'stock' => 0,
                'stock_minimo' => 5,
                'status' => 'critical'
            ]
        );

        return response()->json(['data' => $catalogo], 201);
    }

    public function show(string $id)
    {
        $catalogo = MaderasCatalogo::findOrFail($id);
        return response()->json(['data' => $catalogo]);
    }

    public function update(Request $request, string $id)
    {
        $catalogo = MaderasCatalogo::findOrFail($id);

        $validated = $request->validate([
            'nombre' => 'sometimes|required|string|max:255',
            'tipo' => 'sometimes|required|in:baston,producto_terminado,insumo',
            'unidad_medida' => 'nullable|string|max:50',
        ]);

        $catalogo->update($validated);

        return response()->json(['data' => $catalogo]);
    }

    public function destroy(string $id)
    {
        $catalogo = MaderasCatalogo::findOrFail($id);
        $catalogo->delete();

        return response()->json(null, 204);
    }

    public function productos()
    {
        return response()->json([
            'data' => MaderasCatalogo::where('tipo', 'producto_terminado')->orderBy('nombre')->get()
        ]);
    }

    public function bastones()
    {
        return response()->json([
            'data' => MaderasCatalogo::whereIn('tipo', ['baston', 'insumo'])->orderBy('nombre')->get()
        ]);
    }

    public function dashboard()
    {
        $totalProductos = MaderasCatalogo::where('tipo', 'producto_terminado')->count();
        $totalBastones = MaderasCatalogo::whereIn('tipo', ['baston', 'insumo'])->count();
        
        $produccionHoy = MaderasProduccion::whereDate('fecha_registro', now()->startOfDay())->sum('cantidad');
        $pedidosPendientes = MaderasPedido::where('status', 'pendiente')->count();
        $stockBajo = MaderasInventario::whereIn('status', ['low', 'critical'])->count();
        $ensamblesProceso = MaderasEnsamble::where('status', 'en_proceso')->count();

        return response()->json([
            'total_productos' => $totalProductos,
            'total_bastones' => $totalBastones,
            'produccion_hoy' => (int) $produccionHoy,
            'pedidos_pendientes' => $pedidosPendientes,
            'stock_bajo' => $stockBajo,
            'ensambles_proceso' => $ensamblesProceso,
        ]);
    }
}

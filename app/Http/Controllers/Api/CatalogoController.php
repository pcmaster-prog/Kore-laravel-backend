<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MaderasCatalogo;
use App\Models\MaderasTablaCorte;
use Illuminate\Http\Request;

class CatalogoController extends Controller
{
    public function productos()
    {
        return response()->json([
            'data' => MaderasCatalogo::where('tipo', 'producto_terminado')->orderBy('nombre')->get(),
        ]);
    }

    public function bastones()
    {
        return response()->json([
            'data' => MaderasCatalogo::where('tipo', 'baston')->orderBy('nombre')->get(),
        ]);
    }

    public function tablasCortes()
    {
        return response()->json([
            'data' => MaderasTablaCorte::orderBy('nombre')->get(),
        ]);
    }

    public function storeCatalogo(Request $request)
    {
        $data = $request->validate([
            'nombre' => 'required|string|max:255',
            'tipo' => 'required|in:baston,producto_terminado,insumo',
            'unidad_medida' => 'required|string|max:50',
        ]);

        $item = MaderasCatalogo::create($data);

        return response()->json(['message' => 'Elemento añadido al catálogo', 'data' => $item], 201);
    }

    public function storeTablaCorte(Request $request)
    {
        $data = $request->validate([
            'nombre' => 'required|string|max:255',
            'rendimiento_esperado' => 'required|numeric|min:0.01',
        ]);

        $item = MaderasTablaCorte::create($data);

        return response()->json(['message' => 'Tabla de corte añadida', 'data' => $item], 201);
    }
}

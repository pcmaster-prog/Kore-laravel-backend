<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\MaderasTemporada;

class TemporadasController extends Controller
{
    public function index()
    {
        return response()->json([
            'data' => MaderasTemporada::orderBy('mes_inicio')->get()
        ]);
    }

    public function activa()
    {
        $currentMonth = (int) date('n');
        
        $temporadas = MaderasTemporada::all();
        $activa = null;

        foreach ($temporadas as $temp) {
            if ($temp->mes_inicio <= $temp->mes_fin) {
                if ($currentMonth >= $temp->mes_inicio && $currentMonth <= $temp->mes_fin) {
                    $activa = $temp;
                    break;
                }
            } else {
                // Envuelve el fin de año (ej. 11 a 2)
                if ($currentMonth >= $temp->mes_inicio || $currentMonth <= $temp->mes_fin) {
                    $activa = $temp;
                    break;
                }
            }
        }

        return response()->json([
            'data' => $activa
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre' => 'required|string|max:255',
            'mes_inicio' => 'required|integer|min:1|max:12',
            'mes_fin' => 'required|integer|min:1|max:12',
            'multiplicador' => 'required|numeric|min:0.1',
        ]);

        $item = MaderasTemporada::create($data);

        return response()->json(['message' => 'Temporada añadida', 'data' => $item], 201);
    }
}

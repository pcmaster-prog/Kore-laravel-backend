<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PesajeRegistro;
use Illuminate\Http\Request;

class PesajeController extends Controller
{
    public function index(Request $request)
    {
        $query = PesajeRegistro::with(['empleado', 'sabor'])->orderBy('fecha_registro', 'desc');

        if ($request->has('limit')) {
            $query->limit($request->limit);
        }

        return response()->json([
            'data' => $query->get(),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $empleado = $user->empleado;

        if (! $empleado) {
            return response()->json(['message' => 'Usuario no tiene empleado asociado'], 400);
        }

        $data = $request->validate([
            'sabor_id' => 'required|exists:pesaje_sabors,id',
            'peso' => 'required|numeric|min:0.001',
        ]);

        $data['empleado_id'] = $empleado->id;
        $data['fecha_registro'] = now();

        $item = PesajeRegistro::create($data);

        return response()->json(['message' => 'Pesaje registrado', 'data' => $item], 201);
    }
}

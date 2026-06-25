<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PesajeRegistro;
use Carbon\Carbon;
use Illuminate\Http\Request;

class PesajeRegistroController extends Controller
{
    public function index(Request $request)
    {
        $query = PesajeRegistro::with(['empleado', 'sabor'])->orderBy('fecha_registro', 'desc');

        if ($request->has('limit')) {
            $query->limit($request->limit);
        }

        $registros = $query->get();

        $registros->each(function ($registro) {
            if ($registro->empleado) {
                $parts = explode(' ', $registro->empleado->full_name);
                $registro->empleado->nombres = $parts[0] ?? '';
                $registro->empleado->apellidos = implode(' ', array_slice($parts, 1));
            }
        });

        return response()->json([
            'data' => $registros,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'empleado_id' => 'required|exists:empleados,id',
            'sabor_id' => 'required|exists:pesaje_sabors,id',
            'peso' => 'required|numeric|min:0.001',
        ]);

        $data['fecha_registro'] = now();

        $item = PesajeRegistro::create($data);

        // Load relationships
        $item->load(['empleado', 'sabor']);
        if ($item->empleado) {
            $parts = explode(' ', $item->empleado->full_name);
            $item->empleado->nombres = $parts[0] ?? '';
            $item->empleado->apellidos = implode(' ', array_slice($parts, 1));
        }

        return response()->json(['message' => 'Pesaje registrado', 'data' => $item], 201);
    }

    public function show($id)
    {
        $registro = PesajeRegistro::with(['empleado', 'sabor'])->findOrFail($id);

        if ($registro->empleado) {
            $parts = explode(' ', $registro->empleado->full_name);
            $registro->empleado->nombres = $parts[0] ?? '';
            $registro->empleado->apellidos = implode(' ', array_slice($parts, 1));
        }

        return response()->json(['data' => $registro]);
    }

    public function destroy($id)
    {
        $registro = PesajeRegistro::findOrFail($id);
        $registro->delete();

        return response()->json(['message' => 'Registro de pesaje eliminado']);
    }

    public function dashboard()
    {
        $hoy = Carbon::today();

        $registrosHoy = PesajeRegistro::whereDate('fecha_registro', $hoy)->get();

        $kgHoy = $registrosHoy->sum('peso');
        $viajesHoy = $registrosHoy->count();

        // Stats from yesterday for comparison
        $ayer = Carbon::yesterday();
        $registrosAyer = PesajeRegistro::whereDate('fecha_registro', $ayer)->get();
        $kgAyer = $registrosAyer->sum('peso');

        $trend = $kgAyer > 0 ? (($kgHoy - $kgAyer) / $kgAyer) * 100 : ($kgHoy > 0 ? 100 : 0);

        $ultimosViajes = PesajeRegistro::with(['empleado', 'sabor'])
            ->orderBy('fecha_registro', 'desc')
            ->take(5)
            ->get();

        $ultimosViajes->each(function ($registro) {
            if ($registro->empleado) {
                $parts = explode(' ', $registro->empleado->full_name);
                $registro->empleado->nombres = $parts[0] ?? '';
                $registro->empleado->apellidos = implode(' ', array_slice($parts, 1));
            }
        });

        return response()->json([
            'data' => [
                'kgIngresadosHoy' => round($kgHoy, 2),
                'viajesHoy' => $viajesHoy,
                'tendencia' => round($trend, 1),
                'ultimosViajes' => $ultimosViajes,
            ],
        ]);
    }
}

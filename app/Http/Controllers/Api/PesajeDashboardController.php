<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PesajeRegistro;
use Carbon\Carbon;

class PesajeDashboardController extends Controller
{
    public function index()
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

        return response()->json([
            'data' => [
                'kgIngresadosHoy' => round($kgHoy, 2),
                'viajesHoy' => $viajesHoy,
                'tendencia' => round($trend, 1),
                'ultimosViajes' => PesajeRegistro::with(['empleado', 'sabor'])->orderBy('fecha_registro', 'desc')->take(5)->get(),
            ],
        ]);
    }
}

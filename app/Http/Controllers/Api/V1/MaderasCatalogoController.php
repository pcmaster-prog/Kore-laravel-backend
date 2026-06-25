<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MaderasCatalogoController extends Controller
{
    public function index()
    {
        $productos = DB::table('maderas_productos')->select('id', 'nombre', DB::raw("'producto_terminado' as tipo"))->get();
        $bastones = DB::table('bastones_madera')->select('id', 'nombre', DB::raw("'baston' as tipo"))->get();
        $materias = DB::table('maderas_materias_primas')->select('id', 'nombre', DB::raw("'insumo' as tipo"))->get();

        return response()->json([
            'data' => $productos->concat($bastones)->concat($materias),
        ]);
    }

    public function store(Request $request)
    {
        // Mock to prevent 500, as schema changed
        return response()->json(['message' => 'No implementado en el nuevo esquema'], 400);
    }

    public function show(string $id)
    {
        return response()->json(['data' => []]);
    }

    public function update(Request $request, string $id)
    {
        return response()->json(['data' => []]);
    }

    public function destroy(string $id)
    {
        return response()->json(null, 204);
    }

    public function productos()
    {
        $productos = DB::table('maderas_productos')->select('id', 'nombre', DB::raw("'producto_terminado' as tipo"))->get();

        return response()->json(['data' => $productos]);
    }

    public function bastones()
    {
        $bastones = DB::table('bastones_madera')->select('id', 'nombre', DB::raw("'baston' as tipo"))->get();
        $materias = DB::table('maderas_materias_primas')->select('id', 'nombre', DB::raw("'insumo' as tipo"))->get();

        return response()->json(['data' => $bastones->concat($materias)]);
    }

    public function dashboard()
    {
        try {
            $totalProductos = DB::table('maderas_productos')->count();
            $totalBastones = DB::table('bastones_madera')->count();
            $totalMaterias = DB::table('maderas_materias_primas')->count();

            $produccionHoy = 0;
            $pedidosPendientes = DB::table('pedidos_madera')->where('status', 'pendiente')->count();

            $stockBajo = DB::table('maderas_materias_primas')->whereColumn('stock_actual', '<=', 'alerta_minimo')->count() +
                         DB::table('bastones_madera')->whereColumn('stock', '<=', 'alerta_minimo')->count();

            $ensamblesProceso = 0;

            return response()->json([
                'total_productos' => $totalProductos,
                'total_bastones' => $totalBastones + $totalMaterias,
                'produccion_hoy' => $produccionHoy,
                'pedidos_pendientes' => $pedidosPendientes,
                'stock_bajo' => $stockBajo,
                'ensambles_proceso' => $ensamblesProceso,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Error real del server: '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ], 500);
        }
    }
}

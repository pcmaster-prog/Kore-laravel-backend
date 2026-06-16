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
        $productos = \Illuminate\Support\Facades\DB::table('maderas_productos')->select('id', 'nombre', \Illuminate\Support\Facades\DB::raw("'producto_terminado' as tipo"))->get();
        $bastones = \Illuminate\Support\Facades\DB::table('bastones_madera')->select('id', 'nombre', \Illuminate\Support\Facades\DB::raw("'baston' as tipo"))->get();
        $materias = \Illuminate\Support\Facades\DB::table('maderas_materias_primas')->select('id', 'nombre', \Illuminate\Support\Facades\DB::raw("'insumo' as tipo"))->get();

        return response()->json([
            'data' => $productos->concat($bastones)->concat($materias)
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
        $productos = \Illuminate\Support\Facades\DB::table('maderas_productos')->select('id', 'nombre', \Illuminate\Support\Facades\DB::raw("'producto_terminado' as tipo"))->get();
        return response()->json(['data' => $productos]);
    }

    public function bastones()
    {
        $bastones = \Illuminate\Support\Facades\DB::table('bastones_madera')->select('id', 'nombre', \Illuminate\Support\Facades\DB::raw("'baston' as tipo"))->get();
        $materias = \Illuminate\Support\Facades\DB::table('maderas_materias_primas')->select('id', 'nombre', \Illuminate\Support\Facades\DB::raw("'insumo' as tipo"))->get();
        return response()->json(['data' => $bastones->concat($materias)]);
    }

    public function dashboard()
    {
        $totalProductos = \Illuminate\Support\Facades\DB::table('maderas_productos')->count();
        $totalBastones = \Illuminate\Support\Facades\DB::table('bastones_madera')->count();
        $totalMaterias = \Illuminate\Support\Facades\DB::table('maderas_materias_primas')->count();
        
        $produccionHoy = 0; 
        $pedidosPendientes = \Illuminate\Support\Facades\DB::table('pedidos_madera')->where('status', 'pendiente')->count();
        
        $stockBajo = \Illuminate\Support\Facades\DB::table('maderas_materias_primas')->whereColumn('stock_actual', '<=', 'alerta_minimo')->count() +
                     \Illuminate\Support\Facades\DB::table('bastones_madera')->whereColumn('stock', '<=', 'alerta_minimo')->count();
        
        $ensamblesProceso = 0;

        return response()->json([
            'total_productos' => $totalProductos,
            'total_bastones' => $totalBastones + $totalMaterias,
            'produccion_hoy' => $produccionHoy,
            'pedidos_pendientes' => $pedidosPendientes,
            'stock_bajo' => $stockBajo,
            'ensambles_proceso' => $ensamblesProceso,
        ]);
    }
}

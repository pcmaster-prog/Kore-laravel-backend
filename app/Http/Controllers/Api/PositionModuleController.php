<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Position;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PositionModuleController extends Controller
{
    public function index()
    {
        return response()->json([
            ['slug' => 'produccion_maderas', 'nombre' => 'Prod. Maderas'],
            ['slug' => 'produccion_pesaje', 'nombre' => 'Pesaje'],
        ]);
    }

    public function show($id)
    {
        $position = Position::with('modules')->findOrFail($id);
        $slugs = $position->modules->pluck('module_slug');

        return response()->json(['data' => $slugs]);
    }

    public function sync(Request $request, $id)
    {
        $request->validate([
            'modules' => 'array',
            'modules.*' => 'string',
        ]);

        $position = Position::findOrFail($id);
        $position->modules()->delete();

        foreach ($request->modules as $slug) {
            $position->modules()->create(['module_slug' => $slug]);
        }

        return response()->json(['message' => 'Módulos sincronizados']);
    }

    public function myModules(Request $request)
    {
        $user = $request->user();

        // Obtener los modulos a nivel empresa que estan habilitados
        $companyModules = DB::table('empresa_modules')
            ->where('empresa_id', $user->empresa_id)
            ->where('enabled', true)
            ->pluck('module_slug')
            ->toArray();

        if ($user->role === 'admin') {
            // Asegurar que el admin siempre tenga acceso a todo lo de la empresa
            // mas maderas y pesaje para que pueda ver los dashboards
            return response()->json([
                'modulos' => array_unique(array_merge($companyModules, ['produccion_maderas', 'produccion_pesaje'])),
            ]);
        }

        $empleado = $user->empleado;
        if (! $empleado) {
            return response()->json(['modulos' => $companyModules]);
        }

        $modulosEfectivos = $empleado->modulos_efectivos ?? [];

        return response()->json([
            'modulos' => array_unique(array_merge($companyModules, $modulosEfectivos)),
        ]);
    }
}

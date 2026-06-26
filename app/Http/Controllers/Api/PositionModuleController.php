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
            'data' => [
                ['slug' => 'produccion_maderas', 'nombre' => 'Prod. Maderas'],
                ['slug' => 'produccion_pesaje', 'nombre' => 'Pesaje'],
            ],
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

        // Módulos de producción (Maderas/Pesaje) requieren asignación explícita
        // por puesto u override individual. Si están habilitados a nivel empresa,
        // no se heredan automáticamente por ser empleado.
        $productionModules = ['produccion_maderas', 'produccion_pesaje'];
        $baseModules = array_filter($companyModules, fn (string $slug) => ! in_array($slug, $productionModules, true) || in_array($slug, $modulosEfectivos, true)
        );

        return response()->json([
            'modulos' => array_values(array_unique(array_merge($baseModules, $modulosEfectivos))),
        ]);
    }

    // Obtener los permisos granulares del empleado actual basados en su puesto.
    // Los administradores reciben acceso total a todas las pestañas.
    public function myPermissions(Request $request)
    {
        $user = $request->user();

        if ($user->role === 'admin') {
            return response()->json([
                'data' => [
                    'produccion_maderas' => ['dashboard', 'inventario', 'produccion', 'ensamblaje', 'pedidos'],
                    'produccion_pesaje' => ['dashboard', 'registrar', 'historial'],
                ],
            ]);
        }

        $empleado = $user->empleado;

        if (! $empleado || ! $empleado->position_id) {
            return response()->json(['data' => (object) []]);
        }

        $position = $empleado->position;

        if (! $position) {
            return response()->json(['data' => (object) []]);
        }

        $permissions = $position->permissions ?? [];

        return response()->json(['data' => empty($permissions) ? (object) [] : $permissions]);
    }
}

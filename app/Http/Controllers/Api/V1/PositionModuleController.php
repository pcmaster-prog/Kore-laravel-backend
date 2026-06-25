<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ModulePosition;
use App\Models\Modulo;
use App\Models\Position;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PositionModuleController extends Controller
{
    // Obtener los módulos disponibles para la empresa (para asignarlos a puestos)
    public function index(Request $request)
    {
        $u = $request->user();

        // Obtener módulos habilitados para la empresa
        $enabledKeys = DB::table('empresa_modules')
            ->where('empresa_id', $u->empresa_id)
            ->where('enabled', true)
            ->pluck('module_slug');

        $modules = Modulo::whereIn('key', $enabledKeys)->get()->map(function ($m) {
            return [
                'slug' => $m->key,
                'nombre' => $m->name,
            ];
        });

        return response()->json(['data' => $modules]);
    }

    // Módulos asignados a un puesto en específico
    public function show(Request $request, $id)
    {
        $u = $request->user();
        $position = Position::where('empresa_id', $u->empresa_id)->where('id', $id)->firstOrFail();

        $modules = $position->modules()->pluck('module_slug');

        return response()->json(['data' => $modules]);
    }

    // Sincronizar los módulos de un puesto
    public function sync(Request $request, $id)
    {
        $u = $request->user();
        $position = Position::where('empresa_id', $u->empresa_id)->where('id', $id)->firstOrFail();

        $data = $request->validate([
            'modulos' => ['array'],
            'modulos.*' => ['string'],
        ]);

        // Borramos los actuales
        $position->modules()->delete();

        // Insertamos los nuevos
        if (! empty($data['modulos'])) {
            $inserts = [];
            foreach ($data['modulos'] as $slug) {
                $inserts[] = [
                    'position_id' => $position->id,
                    'module_slug' => $slug,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            ModulePosition::insert($inserts);
        }

        return response()->json(['message' => 'Módulos sincronizados correctamente']);
    }

    // Obtener los módulos que tiene el empleado actual basado en su puesto
    public function myModules(Request $request)
    {
        $u = $request->user();
        $empleado = $u->empleado;

        if (! $empleado || ! $empleado->position_id) {
            return response()->json(['data' => []]);
        }

        $modules = ModulePosition::where('position_id', $empleado->position_id)
            ->pluck('module_slug');

        return response()->json(['data' => $modules]);
    }

    // Obtener los permisos granulares del empleado actual basados en su puesto.
    // Los administradores reciben acceso total a todas las pestañas.
    public function myPermissions(Request $request)
    {
        $u = $request->user();

        if ($u->role === 'admin') {
            return response()->json([
                'data' => [
                    'produccion_maderas' => ['dashboard', 'inventario', 'produccion', 'ensamblaje', 'pedidos'],
                    'produccion_pesaje' => ['dashboard', 'registrar', 'historial'],
                ],
            ]);
        }

        $empleado = $u->empleado;

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

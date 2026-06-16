<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Position;

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
            'modules.*' => 'string'
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
        if ($user->role === 'admin') {
            return response()->json(['modulos' => ['produccion_maderas', 'produccion_pesaje']]);
        }

        $empleado = $user->empleado;
        if (!$empleado) {
            return response()->json(['modulos' => []]);
        }

        return response()->json(['modulos' => $empleado->modulos_efectivos]);
    }
}

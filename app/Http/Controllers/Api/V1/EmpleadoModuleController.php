<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Empleado;

class EmpleadoModuleController extends Controller
{
    /**
     * Devuelve la estructura de módulos esperada por el Frontend:
     * { "efectivos": [...], "heredados": [...], "individuales": [...] }
     */
    public function show($id)
    {
        $empleado = Empleado::findOrFail($id);

        $heredados = $empleado->position ? $empleado->position->modules->pluck('module_slug')->toArray() : [];
        $individuales = $empleado->modulosIndividuales->pluck('module_slug')->toArray();
        $efectivos = $empleado->modulos_efectivos;

        return response()->json([
            'efectivos' => $efectivos,
            'heredados' => array_values(array_unique($heredados)),
            'individuales' => array_values(array_unique($individuales)),
        ]);
    }

    /**
     * Añade un módulo a las excepciones individuales del empleado.
     */
    public function store(Request $request, $id)
    {
        $request->validate([
            'modulo_slug' => 'required|string',
        ]);

        $empleado = Empleado::findOrFail($id);
        
        $exists = $empleado->modulosIndividuales()->where('module_slug', $request->modulo_slug)->exists();
        if (!$exists) {
            $empleado->modulosIndividuales()->create([
                'module_slug' => $request->modulo_slug
            ]);
        }

        return response()->json(['message' => 'Módulo añadido correctamente']);
    }

    /**
     * Elimina un módulo de las excepciones individuales del empleado.
     */
    public function destroy($id, $modulo_slug)
    {
        $empleado = Empleado::findOrFail($id);
        
        $empleado->modulosIndividuales()->where('module_slug', $modulo_slug)->delete();

        return response()->json(['message' => 'Módulo eliminado correctamente']);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\Empleado;
use App\Models\Section;

class EmpleadoSectionsController extends Controller
{
    public function index(Request $request, string $empleadoId)
    {
        $u = $request->user();
        Gate::authorize('supervisor');

        $empleado = Empleado::where('empresa_id', $u->empresa_id)->where('id', $empleadoId)->first();
        if (!$empleado) {
            return response()->json(['message' => 'Empleado no encontrado'], 404);
        }

        $sections = $empleado->sections()->with('area')->orderBy('name')->get();

        return response()->json(['data' => $sections]);
    }

    public function store(Request $request, string $empleadoId)
    {
        $u = $request->user();
        Gate::authorize('admin');

        $empleado = Empleado::where('empresa_id', $u->empresa_id)->where('id', $empleadoId)->first();
        if (!$empleado) {
            return response()->json(['message' => 'Empleado no encontrado'], 404);
        }

        $data = $request->validate([
            'section_id' => ['required', 'uuid', 'exists:sections,id'],
            'is_primary' => ['nullable', 'boolean'],
        ]);

        $section = Section::where('empresa_id', $u->empresa_id)->where('id', $data['section_id'])->first();
        if (!$section) {
            return response()->json(['message' => 'Sección no encontrada'], 404);
        }

        $exists = DB::table('empleado_sections')
            ->where('empresa_id', $u->empresa_id)
            ->where('empleado_id', $empleadoId)
            ->where('section_id', $data['section_id'])
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'El empleado ya está vinculado a esta sección'], 409);
        }

        DB::table('empleado_sections')->insert([
            'id' => (string) Str::uuid(),
            'empresa_id' => $u->empresa_id,
            'empleado_id' => $empleadoId,
            'section_id' => $data['section_id'],
            'is_primary' => $data['is_primary'] ?? false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Vinculado correctamente'], 201);
    }

    public function destroy(Request $request, string $empleadoId, string $sectionId)
    {
        $u = $request->user();
        Gate::authorize('admin');

        $deleted = DB::table('empleado_sections')
            ->where('empresa_id', $u->empresa_id)
            ->where('empleado_id', $empleadoId)
            ->where('section_id', $sectionId)
            ->delete();

        if (!$deleted) {
            return response()->json(['message' => 'Vínculo no encontrado'], 404);
        }

        return response()->json(['message' => 'Desvinculado correctamente']);
    }

    public function sectionEmpleados(Request $request, string $sectionId)
    {
        $u = $request->user();
        Gate::authorize('supervisor');

        $section = Section::where('empresa_id', $u->empresa_id)->where('id', $sectionId)->first();
        if (!$section) {
            return response()->json(['message' => 'Sección no encontrada'], 404);
        }

        $empleados = $section->empleados()->with('position')->orderBy('full_name')->get();

        return response()->json(['data' => $empleados]);
    }
}

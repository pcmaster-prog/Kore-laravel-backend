<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use App\Models\Section;
use App\Models\Empleado;

class SectionsController extends Controller
{
    public function index(Request $request)
    {
        $u = $request->user();
        Gate::authorize('supervisor');

        $q = Section::where('empresa_id', $u->empresa_id);

        if ($request->filled('area_id')) {
            $q->where('area_id', $request->string('area_id'));
        }

        if ($request->filled('active')) {
            $q->where('is_active', $request->boolean('active'));
        }

        return response()->json($q->orderBy('sort_order')->get());
    }

    public function byArea(Request $request, string $areaId)
    {
        $u = $request->user();
        Gate::authorize('supervisor');

        $sections = Section::where('empresa_id', $u->empresa_id)
            ->where('area_id', $areaId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return response()->json(['data' => $sections]);
    }

    public function store(Request $request)
    {
        $u = $request->user();
        Gate::authorize('admin');

        $data = $request->validate([
            'area_id' => ['required', 'uuid', 'exists:areas,id'],
            'name' => ['required', 'string', 'max:120'],
            'sort_order' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        // Validar que el área pertenezca a la empresa
        $area = \App\Models\Area::where('empresa_id', $u->empresa_id)->where('id', $data['area_id'])->first();
        if (!$area) {
            return response()->json(['message' => 'Área no encontrada'], 404);
        }

        $section = Section::create([
            'empresa_id' => $u->empresa_id,
            'area_id' => $data['area_id'],
            'name' => $data['name'],
            'sort_order' => $data['sort_order'] ?? 0,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return response()->json(['item' => $section], 201);
    }

    public function show(Request $request, string $id)
    {
        $u = $request->user();
        Gate::authorize('supervisor');

        $section = Section::where('empresa_id', $u->empresa_id)->where('id', $id)->first();
        if (!$section) return response()->json(['message' => 'No encontrado'], 404);

        return response()->json(['item' => $section]);
    }

    public function update(Request $request, string $id)
    {
        $u = $request->user();
        Gate::authorize('admin');

        $section = Section::where('empresa_id', $u->empresa_id)->where('id', $id)->first();
        if (!$section) return response()->json(['message' => 'No encontrado'], 404);

        $data = $request->validate([
            'area_id' => ['sometimes', 'uuid', 'exists:areas,id'],
            'name' => ['sometimes', 'string', 'max:120'],
            'sort_order' => ['sometimes', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        // Validar que el área nueva pertenezca a la empresa
        if (isset($data['area_id'])) {
            $area = \App\Models\Area::where('empresa_id', $u->empresa_id)->where('id', $data['area_id'])->first();
            if (!$area) {
                return response()->json(['message' => 'Área no encontrada'], 404);
            }
        }

        $section->fill($data);
        $section->save();

        return response()->json(['item' => $section]);
    }

    public function empleados(Request $request, string $id)
    {
        $u = $request->user();
        Gate::authorize('supervisor');

        $section = Section::where('empresa_id', $u->empresa_id)->where('id', $id)->first();
        if (!$section) return response()->json(['message' => 'No encontrado'], 404);

        $empleados = Empleado::where('empresa_id', $u->empresa_id)
            ->where('status', 'active')
            ->whereHas('sections', fn($q) => $q->where('section_id', $id))
            ->with('user')
            ->get()
            ->map(fn($emp) => [
                'id' => $emp->id,
                'full_name' => $emp->full_name,
                'position_title' => $emp->position_title,
                'avatar_url' => $emp->user?->avatar_url,
            ]);

        return response()->json(['data' => $empleados]);
    }

    public function destroy(Request $request, string $id)
    {
        $u = $request->user();
        Gate::authorize('admin');

        $section = Section::where('empresa_id', $u->empresa_id)->where('id', $id)->first();
        if (!$section) return response()->json(['message' => 'No encontrado'], 404);

        $section->delete();
        return response()->json(['message' => 'Eliminado']);
    }
}

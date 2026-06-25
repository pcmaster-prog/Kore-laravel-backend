<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Section;
use App\Models\SupervisorSection;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SupervisorSectionsController extends Controller
{
    public function mySections(Request $request)
    {
        $u = $request->user();
        Gate::authorize('supervisor');

        $sections = Section::whereHas('supervisors', function ($q) use ($u) {
            $q->where('supervisor_user_id', $u->id);
        })
            ->where('empresa_id', $u->empresa_id)
            ->where('is_active', true)
            ->with('area')
            ->orderBy('sort_order')
            ->get();

        return response()->json(['data' => $sections]);
    }

    public function assign(Request $request)
    {
        $u = $request->user();
        Gate::authorize('admin');

        $data = $request->validate([
            'supervisor_user_id' => ['required', 'uuid', 'exists:users,id'],
            'section_id' => ['required', 'uuid', 'exists:sections,id'],
        ]);

        // Validar que la sección pertenezca a la empresa
        $section = Section::where('empresa_id', $u->empresa_id)->where('id', $data['section_id'])->first();
        if (! $section) {
            return response()->json(['message' => 'Sección no encontrada'], 404);
        }

        // Validar que el supervisor pertenezca a la empresa
        $supervisor = User::where('empresa_id', $u->empresa_id)
            ->where('id', $data['supervisor_user_id'])
            ->whereIn('role', ['supervisor', 'admin'])
            ->first();
        if (! $supervisor) {
            return response()->json(['message' => 'Supervisor no encontrado'], 404);
        }

        $assignment = SupervisorSection::firstOrCreate([
            'empresa_id' => $u->empresa_id,
            'supervisor_user_id' => $data['supervisor_user_id'],
            'section_id' => $data['section_id'],
        ]);

        return response()->json(['item' => $assignment], 201);
    }

    public function destroy(Request $request, string $id)
    {
        $u = $request->user();
        Gate::authorize('admin');

        $assignment = SupervisorSection::where('empresa_id', $u->empresa_id)->where('id', $id)->first();
        if (! $assignment) {
            return response()->json(['message' => 'No encontrado'], 404);
        }

        $assignment->delete();

        return response()->json(['message' => 'Eliminado']);
    }
}

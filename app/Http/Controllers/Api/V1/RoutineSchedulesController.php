<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Gate;
use App\Models\RoutineSchedule;
use App\Models\TaskRoutine;
use App\Models\Empleado;
use App\Models\Position;
use App\Models\Area;
use App\Models\Section;

class RoutineSchedulesController extends Controller
{
    public function index(Request $request)
    {
        $u = $request->user();
        Gate::authorize('supervisor');

        $q = RoutineSchedule::where('empresa_id', $u->empresa_id)
            ->with('routine');

        if ($request->filled('routine_id')) {
            $q->where('routine_id', $request->string('routine_id'));
        }

        if ($request->filled('active')) {
            $q->where('is_active', $request->boolean('active'));
        }

        return response()->json($q->orderBy('trigger_time')->get());
    }

    public function store(Request $request)
    {
        $u = $request->user();
        Gate::authorize('admin');

        $data = $request->validate([
            'routine_id' => ['required', 'uuid', 'exists:task_routines,id'],
            'trigger_time' => ['required', 'date_format:H:i'],
            'trigger_days' => ['required', 'array'],
            'trigger_days.*' => ['integer', 'min:0', 'max:6'],
            'auto_assign' => ['nullable', 'boolean'],
            'notify_push' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'assignee_type' => ['nullable', Rule::in(['empleado', 'position', 'section', 'area'])],
            'assignee_id' => ['nullable', 'uuid'],
            'area_id' => ['nullable', 'uuid', 'exists:areas,id'],
            'section_id' => ['nullable', 'uuid', 'exists:sections,id'],
        ]);

        // Validar que la rutina pertenezca a la empresa
        $routine = TaskRoutine::where('empresa_id', $u->empresa_id)->where('id', $data['routine_id'])->first();
        if (!$routine) {
            return response()->json(['message' => 'Rutina no encontrada'], 404);
        }

        // Validaciones adicionales de nuevos campos
        if (!empty($data['assignee_type']) && $data['assignee_type'] === 'empleado' && !empty($data['assignee_id'])) {
            $empleado = Empleado::where('empresa_id', $u->empresa_id)->where('id', $data['assignee_id'])->where('status', 'active')->first();
            if (!$empleado) {
                return response()->json(['message' => 'Empleado no válido o inactivo'], 422);
            }
        }

        if (!empty($data['assignee_type']) && $data['assignee_type'] === 'position' && !empty($data['assignee_id'])) {
            $position = Position::where('empresa_id', $u->empresa_id)->where('id', $data['assignee_id'])->first();
            if (!$position) {
                return response()->json(['message' => 'Posición no válida'], 422);
            }
        }

        if (!empty($data['assignee_type']) && $data['assignee_type'] === 'section' && empty($data['section_id'])) {
            return response()->json(['message' => 'section_id es requerido cuando assignee_type es section'], 422);
        }

        if (!empty($data['assignee_type']) && $data['assignee_type'] === 'area' && empty($data['area_id'])) {
            return response()->json(['message' => 'area_id es requerido cuando assignee_type es area'], 422);
        }

        if (!empty($data['area_id'])) {
            $area = Area::where('empresa_id', $u->empresa_id)->where('id', $data['area_id'])->first();
            if (!$area) {
                return response()->json(['message' => 'Área no encontrada'], 404);
            }
        }

        if (!empty($data['section_id'])) {
            $section = Section::where('empresa_id', $u->empresa_id)->where('id', $data['section_id'])->first();
            if (!$section) {
                return response()->json(['message' => 'Sección no encontrada'], 404);
            }
        }

        $schedule = RoutineSchedule::create([
            'empresa_id' => $u->empresa_id,
            'routine_id' => $data['routine_id'],
            'created_by' => $u->id,
            'trigger_time' => $data['trigger_time'],
            'trigger_days' => $data['trigger_days'],
            'auto_assign' => $data['auto_assign'] ?? true,
            'notify_push' => $data['notify_push'] ?? true,
            'is_active' => $data['is_active'] ?? true,
            'assignee_type' => $data['assignee_type'] ?? null,
            'assignee_id' => $data['assignee_id'] ?? null,
            'area_id' => $data['area_id'] ?? null,
            'section_id' => $data['section_id'] ?? null,
        ]);

        return response()->json(['item' => $schedule], 201);
    }

    public function show(Request $request, string $id)
    {
        $u = $request->user();
        Gate::authorize('supervisor');

        $schedule = RoutineSchedule::where('empresa_id', $u->empresa_id)->where('id', $id)->first();
        if (!$schedule) return response()->json(['message' => 'No encontrado'], 404);

        return response()->json(['item' => $schedule]);
    }

    public function update(Request $request, string $id)
    {
        $u = $request->user();
        Gate::authorize('admin');

        $schedule = RoutineSchedule::where('empresa_id', $u->empresa_id)->where('id', $id)->first();
        if (!$schedule) return response()->json(['message' => 'No encontrado'], 404);

        $data = $request->validate([
            'trigger_time' => ['sometimes', 'date_format:H:i'],
            'trigger_days' => ['sometimes', 'array'],
            'trigger_days.*' => ['integer', 'min:0', 'max:6'],
            'auto_assign' => ['sometimes', 'boolean'],
            'notify_push' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'assignee_type' => ['sometimes', 'nullable', Rule::in(['empleado', 'position', 'section', 'area'])],
            'assignee_id' => ['sometimes', 'nullable', 'uuid'],
            'area_id' => ['sometimes', 'nullable', 'uuid', 'exists:areas,id'],
            'section_id' => ['sometimes', 'nullable', 'uuid', 'exists:sections,id'],
        ]);

        $schedule->fill($data);
        $schedule->save();

        return response()->json(['item' => $schedule]);
    }

    public function destroy(Request $request, string $id)
    {
        $u = $request->user();
        Gate::authorize('admin');

        $schedule = RoutineSchedule::where('empresa_id', $u->empresa_id)->where('id', $id)->first();
        if (!$schedule) return response()->json(['message' => 'No encontrado'], 404);

        $schedule->delete();
        return response()->json(['message' => 'Eliminado']);
    }
}

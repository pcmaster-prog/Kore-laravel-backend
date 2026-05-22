<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Gate;
use App\Models\RoutineSchedule;
use App\Models\TaskRoutine;

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
        ]);

        // Validar que la rutina pertenezca a la empresa
        $routine = TaskRoutine::where('empresa_id', $u->empresa_id)->where('id', $data['routine_id'])->first();
        if (!$routine) {
            return response()->json(['message' => 'Rutina no encontrada'], 404);
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

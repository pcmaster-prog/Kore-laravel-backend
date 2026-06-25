<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use App\Models\Task;
use App\Models\TaskAssignee;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class IncidentsController extends Controller
{
    public function index(Request $request)
    {
        $u = $request->user();
        Gate::authorize('supervisor');

        $q = Incident::where('incidents.empresa_id', $u->empresa_id)
            ->with(['task', 'taskAssignee.empleado', 'reporter']);

        if ($request->filled('status')) {
            $q->where('status', $request->string('status'));
        }

        if ($request->filled('type')) {
            $q->where('type', $request->string('type'));
        }

        if ($request->filled('task_id')) {
            $q->where('task_id', $request->string('task_id'));
        }

        if ($request->filled('from')) {
            $q->whereDate('incidents.created_at', '>=', $request->string('from'));
        }

        if ($request->filled('to')) {
            $q->whereDate('incidents.created_at', '<=', $request->string('to'));
        }

        return response()->json($q->orderBy('created_at', 'desc')->paginate(20));
    }

    public function store(Request $request)
    {
        $u = $request->user();

        $data = $request->validate([
            'task_id' => ['required', 'uuid', 'exists:tasks,id'],
            'task_assignee_id' => ['nullable', 'uuid', 'exists:task_assignees,id'],
            'type' => ['required', Rule::in(['missing_material', 'broken_equipment', 'other'])],
            'description' => ['required', 'string', 'max:2000'],
        ]);

        // Validar que la tarea pertenezca a la empresa
        $task = Task::where('empresa_id', $u->empresa_id)->where('id', $data['task_id'])->first();
        if (! $task) {
            return response()->json(['message' => 'Tarea no encontrada'], 404);
        }

        // Validar que el assignee pertenezca a la tarea
        if (! empty($data['task_assignee_id'])) {
            $assignee = TaskAssignee::where('task_id', $data['task_id'])
                ->where('id', $data['task_assignee_id'])
                ->first();
            if (! $assignee) {
                return response()->json(['message' => 'Asignación no encontrada'], 404);
            }
        }

        $incident = Incident::create([
            'empresa_id' => $u->empresa_id,
            'task_id' => $data['task_id'],
            'task_assignee_id' => $data['task_assignee_id'] ?? null,
            'reported_by' => $u->id,
            'type' => $data['type'],
            'description' => $data['description'],
            'status' => 'open',
        ]);

        // Incrementar contador de incidentes en la tarea
        $task->increment('incident_count');

        ActivityLogger::log(
            $u->empresa_id, $u->id, null,
            'incident.created', 'incident', $incident->id,
            ['task_title' => $task->title, 'type' => $data['type']],
            $request
        );

        return response()->json(['item' => $incident], 201);
    }

    public function resolve(Request $request, string $id)
    {
        $u = $request->user();
        Gate::authorize('supervisor');

        $incident = Incident::where('empresa_id', $u->empresa_id)->where('id', $id)->first();
        if (! $incident) {
            return response()->json(['message' => 'No encontrado'], 404);
        }

        $incident->status = 'resolved';
        $incident->resolved_by = $u->id;
        $incident->resolved_at = now();
        $incident->save();

        return response()->json(['item' => $incident]);
    }

    public function dismiss(Request $request, string $id)
    {
        $u = $request->user();
        Gate::authorize('supervisor');

        $incident = Incident::where('empresa_id', $u->empresa_id)->where('id', $id)->first();
        if (! $incident) {
            return response()->json(['message' => 'No encontrado'], 404);
        }

        $incident->status = 'dismissed';
        $incident->resolved_by = $u->id;
        $incident->resolved_at = now();
        $incident->save();

        return response()->json(['item' => $incident]);
    }
}

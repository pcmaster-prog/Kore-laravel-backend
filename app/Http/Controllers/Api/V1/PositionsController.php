<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ModulePosition;
use App\Models\Position;
use App\Models\PositionTask;
use App\Models\TaskTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PositionsController extends Controller
{
    public function index(Request $request)
    {
        $u = $request->user();
        Gate::authorize('supervisor');

        $q = Position::where('empresa_id', $u->empresa_id);

        if ($request->filled('active')) {
            $q->where('is_active', $request->boolean('active'));
        }

        if ($request->filled('search')) {
            $s = $request->string('search');
            $q->where('name', 'ilike', "%{$s}%");
        }

        $positions = $q->orderBy('name')->withCount('empleados')->with('modules')->get()->map(function ($p) {
            return [
                'id' => $p->id,
                'nombre' => $p->name,
                'descripcion' => $p->description,
                'activo' => $p->is_active,
                'empleados_count' => $p->empleados_count,
                'modulos' => $p->modules->pluck('module_slug'),
                'permisos' => $p->permissions ?? (object) [],
            ];
        });

        return response()->json(['data' => $positions]);
    }

    public function store(Request $request)
    {
        $u = $request->user();
        Gate::authorize('admin');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
            'permissions' => ['nullable', 'array'],
            'modulos' => ['nullable', 'array'],
            'modulos.*' => ['string', 'max:100'],
        ]);

        $position = Position::create([
            'empresa_id' => $u->empresa_id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'permissions' => $data['permissions'] ?? [],
        ]);

        if (array_key_exists('modulos', $data)) {
            $this->syncModules($position, $data['modulos']);
        }

        return response()->json(['data' => [
            'id' => $position->id,
            'nombre' => $position->name,
            'descripcion' => $position->description,
            'activo' => $position->is_active,
            'modulos' => [],
            'permisos' => $position->permissions ?? (object) [],
        ]], 201);
    }

    public function show(Request $request, string $id)
    {
        $u = $request->user();
        Gate::authorize('supervisor');

        $position = Position::where('empresa_id', $u->empresa_id)->where('id', $id)->first();
        if (! $position) {
            return response()->json(['message' => 'No encontrado'], 404);
        }

        $position->loadCount('empleados');
        $position->load('modules');

        return response()->json(['data' => [
            'id' => $position->id,
            'nombre' => $position->name,
            'descripcion' => $position->description,
            'activo' => $position->is_active,
            'empleados_count' => $position->empleados_count,
            'modulos' => $position->modules->pluck('module_slug'),
            'permisos' => $position->permissions ?? (object) [],
        ]]);
    }

    public function update(Request $request, string $id)
    {
        $u = $request->user();
        Gate::authorize('admin');

        $position = Position::where('empresa_id', $u->empresa_id)->where('id', $id)->first();
        if (! $position) {
            return response()->json(['message' => 'No encontrado'], 404);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'permissions' => ['sometimes', 'nullable', 'array'],
            'modulos' => ['sometimes', 'nullable', 'array'],
            'modulos.*' => ['string', 'max:100'],
        ]);

        $position->fill($data);
        $position->save();

        if (array_key_exists('modulos', $data)) {
            $this->syncModules($position, $data['modulos']);
        }

        $position->loadCount('empleados');
        $position->load('modules');

        return response()->json(['data' => [
            'id' => $position->id,
            'nombre' => $position->name,
            'descripcion' => $position->description,
            'activo' => $position->is_active,
            'empleados_count' => $position->empleados_count,
            'modulos' => $position->modules->pluck('module_slug'),
            'permisos' => $position->permissions ?? (object) [],
        ]]);
    }

    private function syncModules(Position $position, array $slugs): void
    {
        $position->modules()->delete();

        if (empty($slugs)) {
            return;
        }

        $now = now();
        $inserts = array_map(fn (string $slug) => [
            'position_id' => $position->id,
            'module_slug' => $slug,
            'created_at' => $now,
            'updated_at' => $now,
        ], $slugs);

        ModulePosition::insert($inserts);
    }

    public function destroy(Request $request, string $id)
    {
        $u = $request->user();
        Gate::authorize('admin');

        $position = Position::where('empresa_id', $u->empresa_id)->where('id', $id)->first();
        if (! $position) {
            return response()->json(['message' => 'No encontrado'], 404);
        }

        $position->delete();

        return response()->json(['message' => 'Eliminado']);
    }

    public function baseTasks(Request $request, string $id)
    {
        $u = $request->user();
        Gate::authorize('supervisor');

        $position = Position::where('empresa_id', $u->empresa_id)->where('id', $id)->first();
        if (! $position) {
            return response()->json(['message' => 'No encontrado'], 404);
        }

        $tasks = $position->baseTasks()->get();

        return response()->json(['data' => $tasks]);
    }

    public function syncBaseTasks(Request $request, string $id)
    {
        $u = $request->user();
        Gate::authorize('admin');

        $position = Position::where('empresa_id', $u->empresa_id)->where('id', $id)->first();
        if (! $position) {
            return response()->json(['message' => 'No encontrado'], 404);
        }

        $data = $request->validate([
            'tasks' => ['required', 'array'],
            'tasks.*.template_id' => ['required', 'uuid'],
            'tasks.*.is_required' => ['nullable', 'boolean'],
            'tasks.*.sort_order' => ['nullable', 'integer'],
        ]);

        // Validar que todos los templates pertenezcan a la empresa
        $templateIds = collect($data['tasks'])->pluck('template_id')->all();
        $validCount = TaskTemplate::where('empresa_id', $u->empresa_id)->whereIn('id', $templateIds)->count();
        if ($validCount !== count($templateIds)) {
            return response()->json(['message' => 'Uno o más templates no pertenecen a la empresa'], 422);
        }

        // Eliminar relaciones actuales y recrear
        PositionTask::where('empresa_id', $u->empresa_id)->where('position_id', $id)->delete();

        foreach ($data['tasks'] as $task) {
            PositionTask::create([
                'empresa_id' => $u->empresa_id,
                'position_id' => $id,
                'task_template_id' => $task['template_id'],
                'is_required' => $task['is_required'] ?? true,
                'sort_order' => $task['sort_order'] ?? 0,
            ]);
        }

        return response()->json(['message' => 'Tareas base actualizadas']);
    }
}

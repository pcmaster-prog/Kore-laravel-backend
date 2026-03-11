<?php
//TaskCatalogController: endpoint para mostrar catálogo de tareas basado en rutinas e instanciar tareas desde templates (con opción de evitar duplicados por fecha+template)
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

use App\Models\TaskTemplate;
use App\Models\TaskRoutine;
use App\Models\TaskRoutineItem;
use App\Models\Task;
use App\Models\TaskAssignee;
use App\Models\Empleado;

class TaskCatalogController extends Controller
{
    private function requireManager($u)
    {
        if (!in_array($u->role, ['admin','supervisor'])) {
            abort(response()->json(['message'=>'No autorizado'], 403));
        }
    }

    public function catalog(Request $request)
    {
        $u = $request->user();
        $this->requireManager($u);

        $empresaId = $u->empresa_id;
        $date = $request->input('date', now()->toDateString());
        $dow = \Carbon\Carbon::parse($date)->dayOfWeek;

        $routines = TaskRoutine::where('empresa_id',$empresaId)
            ->where('is_active', true)
            ->get()
            ->filter(function ($r) use ($date, $dow) {
                if ($r->start_date && $date < $r->start_date->toDateString()) return false;
                if ($r->end_date && $date > $r->end_date->toDateString()) return false;

                if ($r->recurrence === 'daily') return true;
                if ($r->recurrence === 'weekly') {
                    $w = is_array($r->weekdays) ? $r->weekdays : [];
                    return in_array($dow, $w, true);
                }
                return false;
            })
            ->values();

        // ✅ Bonus: mapa para acceder rápido al nombre de la rutina
        $routinesById = $routines->keyBy('id');

        $routineIds = $routines->pluck('id')->all();

        $items = TaskRoutineItem::where('empresa_id',$empresaId)
            ->whereIn('routine_id',$routineIds)
            ->where('is_active', true)
            ->orderBy('routine_id')
            ->orderBy('sort_order')
            ->get();

        $templateIds = $items->pluck('template_id')->unique()->values()->all();

        $templates = TaskTemplate::where('empresa_id',$empresaId)
            ->whereIn('id',$templateIds)
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        $catalog = $items->map(function ($it) use ($templates, $routinesById) {
            $t = $templates->get($it->template_id);
            if (!$t) return null;

            return [
                'routine_item_id' => $it->id,
                'routine_id' => $it->routine_id,                          // ✅ Agregado
                'routine_name' => $routinesById->get($it->routine_id)?->name ?? null, // ✅ Bonus
                'template' => $t,
                'sort_order' => $it->sort_order,
            ];
        })->filter()->values();

        return response()->json([
            'date' => $date,
            'dow' => $dow,
            'routines' => $routines,
            'catalog' => $catalog,
        ]);
    }

    public function createFromTemplate(Request $request)
    {
        $u = $request->user();
        $this->requireManager($u);

        $empresaId = $u->empresa_id;

        $data = $request->validate([
            'template_id' => ['required','uuid'],
            'empleado_ids' => ['required','array','min:1'],
            'empleado_ids.*' => ['uuid'],
            'date' => ['nullable','date'],
            'due_at' => ['nullable','date'],
        ]);

        $tpl = TaskTemplate::where('empresa_id',$empresaId)->where('id',$data['template_id'])->first();
        if (!$tpl) return response()->json(['message'=>'Template no encontrado'], 404);

        $validEmployees = Empleado::where('empresa_id',$empresaId)
            ->whereIn('id', $data['empleado_ids'])
            ->pluck('id')->all();

        if (count($validEmployees) !== count($data['empleado_ids'])) {
            return response()->json(['message'=>'Uno o más empleados no pertenecen a la empresa'], 422);
        }

        $catalogDate = $data['date'] ?? now()->toDateString();

        $taskResult = DB::transaction(function () use ($u, $empresaId, $tpl, $validEmployees, $data, $catalogDate) {
            $existingTask = \App\Models\Task::where('empresa_id', $empresaId)
                ->whereRaw("meta->>'template_id' = ?", [$tpl->id])
                ->whereRaw("meta->>'catalog_date' = ?", [$catalogDate])
                ->first();

            if ($existingTask) {
                foreach ($validEmployees as $empId) {
                    \App\Models\TaskAssignee::firstOrCreate(
                        ['empresa_id' => $empresaId, 'task_id' => $existingTask->id, 'empleado_id' => $empId],
                        ['status' => 'assigned']
                    );
                }
                return ['task' => $existingTask, 'reused' => true];
            }

            $meta = $tpl->meta ?? [];
            $meta['template_id'] = $tpl->id;
            $meta['catalog_date'] = $catalogDate;

            $task = Task::create([
                'empresa_id' => $empresaId,
                'created_by' => $u->id,
                'title' => $tpl->title,
                'description' => $tpl->description,
                'priority' => $tpl->priority,
                'status' => 'open',
                'due_at' => $data['due_at'] ?? null,
                'meta' => $meta,
            ]);

            foreach ($validEmployees as $empId) {
                TaskAssignee::firstOrCreate(
                    ['empresa_id'=>$empresaId,'task_id'=>$task->id,'empleado_id'=>$empId],
                    ['status'=>'assigned']
                );
            }

            if ($task->status === 'open') {
                $task->status = 'in_progress';
                $task->save();
            }

            return ['task' => $task, 'reused' => false];
        });

        $actualTask = $taskResult['task'];
        $wasReused = $taskResult['reused'];

        \App\Services\ActivityLogger::log(
            $empresaId,
            $u->id,
            null,
            $wasReused ? 'task.reused_from_template' : 'task.created_from_template',
            'task',
            $actualTask->id,
            [
                'template_id' => $tpl->id,
                'task_title' => $tpl->title,           // <-- AGREGADO: título de la tarea
                'catalog_date' => $catalogDate,
                'created_by_name' => $u->name,
                'empleado_ids' => $validEmployees
            ],
            $request
        );

        return response()->json(['message'=>'Tarea creada desde template', 'task'=>$actualTask], 201);
    }

    public function createBulkFromTemplates(Request $request)
    {
        $u = $request->user();
        $this->requireManager($u);

        $empresaId = $u->empresa_id;

        $data = $request->validate([
            'date' => ['required','date'],
            'template_ids' => ['required','array','min:1'],
            'template_ids.*' => ['uuid'],
            'empleado_ids' => ['required','array','min:1'],
            'empleado_ids.*' => ['uuid'],
            'due_at' => ['nullable','date'],
            'allow_duplicate' => ['nullable','boolean'],
        ]);

        $allowDuplicate = (bool)($data['allow_duplicate'] ?? false);
        $catalogDate = $data['date'];

        $validEmployees = \App\Models\Empleado::where('empresa_id',$empresaId)
            ->whereIn('id', $data['empleado_ids'])
            ->pluck('id')->all();

        if (count($validEmployees) !== count($data['empleado_ids'])) {
            return response()->json(['message'=>'Uno o más empleados no pertenecen a la empresa'], 422);
        }

        $templates = \App\Models\TaskTemplate::where('empresa_id',$empresaId)
            ->whereIn('id', $data['template_ids'])
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        $results = [
            'created_tasks' => [],
            'reused_tasks' => [],
            'skipped' => [],
            'errors' => [],
        ];

        foreach ($data['template_ids'] as $tplId) {
            $tpl = $templates->get($tplId);
            if (!$tpl) {
                $results['errors'][] = ['template_id'=>$tplId, 'reason'=>'Template no válido o inactivo'];
                continue;
            }

            try {
                $out = \Illuminate\Support\Facades\DB::transaction(function () use ($u, $empresaId, $tpl, $validEmployees, $data, $allowDuplicate) {
                    $existingTask = null;

                    if (!$allowDuplicate) {
                        $existingTask = \App\Models\Task::where('empresa_id',$empresaId)
                            ->whereRaw("meta->>'template_id' = ?", [$tpl->id])
                            ->whereRaw("meta->>'catalog_date' = ?", [$data['date']])
                            ->first();
                    }

                    if ($existingTask) {
                        $already = \App\Models\TaskAssignee::where('empresa_id',$empresaId)
                            ->where('task_id',$existingTask->id)
                            ->whereIn('empleado_id',$validEmployees)
                            ->pluck('empleado_id')->all();

                        $toAdd = array_values(array_diff($validEmployees, $already));

                        foreach ($toAdd as $empId) {
                            \App\Models\TaskAssignee::firstOrCreate(
                                ['empresa_id'=>$empresaId,'task_id'=>$existingTask->id,'empleado_id'=>$empId],
                                ['status'=>'assigned']
                            );
                        }

                        return [
                            'mode' => 'reused',
                            'task_id' => $existingTask->id,
                            'added_empleados' => $toAdd,
                            'skipped_empleados' => $already,
                        ];
                    }

                    $meta = $tpl->meta ?? [];
                    $meta['template_id'] = $tpl->id;
                    $meta['catalog_date'] = $data['date'];

                    $task = \App\Models\Task::create([
                        'empresa_id' => $empresaId,
                        'created_by' => $u->id,
                        'title' => $tpl->title,
                        'description' => $tpl->description,
                        'priority' => $tpl->priority,
                        'status' => 'open',
                        'due_at' => $data['due_at'] ?? null,
                        'meta' => $meta,
                    ]);

                    foreach ($validEmployees as $empId) {
                        \App\Models\TaskAssignee::firstOrCreate(
                            ['empresa_id'=>$empresaId,'task_id'=>$task->id,'empleado_id'=>$empId],
                            ['status'=>'assigned']
                        );
                    }

                    $task->status = 'in_progress';
                    $task->save();

                    return [
                        'mode' => 'created',
                        'task_id' => $task->id,
                        'added_empleados' => $validEmployees,
                        'skipped_empleados' => [],
                    ];
                });

                if ($out['mode'] === 'created') {
                    // 🔔 PARCHE 2: Logging con task_title para bulk_created
                    \App\Services\ActivityLogger::log(
                        $empresaId,
                        $u->id,
                        null,
                        'task.bulk_created',
                        'task',
                        $out['task_id'],
                        [
                            'template_id' => $tplId,
                            'task_title'  => $tpl->title,        // <-- AGREGADO: título de la tarea
                            'catalog_date' => $catalogDate,
                            'created_by_name' => $u->name,
                            'empleados_added' => $out['added_empleados'],
                        ],
                        $request
                    );

                    $results['created_tasks'][] = [
                        'template_id' => $tplId,
                        'task_id' => $out['task_id'],
                        'empleados_added' => $out['added_empleados'],
                    ];
                } else {
                    // 🔔 PARCHE 2: Logging con task_title para bulk_reused (consistencia)
                    \App\Services\ActivityLogger::log(
                        $empresaId,
                        $u->id,
                        null,
                        'task.bulk_reused',
                        'task',
                        $out['task_id'],
                        [
                            'template_id' => $tplId,
                            'task_title'  => $tpl->title,  
                            'created_by_name' => $u->name,      // <-- AGREGADO: título de la tarea
                            'catalog_date' => $catalogDate,
                            'empleados_added' => $out['added_empleados'],
                            'empleados_skipped' => $out['skipped_empleados'],
                        ],
                        $request
                    );

                    $results['reused_tasks'][] = [
                        'template_id' => $tplId,
                        'task_id' => $out['task_id'],
                        'empleados_added' => $out['added_empleados'],
                        'empleados_skipped' => $out['skipped_empleados'],
                    ];

                    if (count($out['added_empleados']) === 0) {
                        $results['skipped'][] = [
                            'template_id' => $tplId,
                            'reason' => 'Todos los empleados ya tenían asignada esta tarea (template+fecha)',
                            'task_id' => $out['task_id'],
                        ];
                    }
                }

            } catch (\Throwable $e) {
                $results['errors'][] = ['template_id'=>$tplId, 'reason'=>$e->getMessage()];
            }
        }

        return response()->json([
            'message' => 'Bulk create completed (idempotent per employee)',
            'results' => $results,
        ], 201);
    }
}
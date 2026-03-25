<?php
//TaskController: manejo de tareas, asignaciones, checklist y evidencias
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

use App\Models\Task;
use App\Models\TaskAssignee;
use App\Models\Empleado;
use App\Models\Evidence;

class TasksController extends Controller
{
    public function index(Request $request)
    {
        $u = $request->user();

        if (!in_array($u->role, ['admin','supervisor'])) {
            return response()->json(['message'=>'No autorizado'], 403);
        }

        $q = Task::where('empresa_id', $u->empresa_id);

        if ($request->filled('status')) {
            $q->whereIn('status', explode(',', $request->string('status')));
        }

        if ($request->filled('priority')) {
            $q->where('priority', $request->string('priority'));
        }

        if ($request->filled('empleado_id')) {
            $empId = $request->string('empleado_id');
            $q->whereHas('assignees', fn ($a) =>
                $a->where('empleado_id', $empId)
            );
        }

        if ($request->filled('date')) {
            $q->whereRaw("meta->>'catalog_date' = ?", [$request->string('date')]);
        }

        if ($request->filled('search')) {
            $s = $request->string('search');
            $q->where('title','ilike',"%{$s}%");
        }

        if ($request->boolean('overdue')) {
            $q->whereNot('status','completed')
              ->whereNotNull('due_at')
              ->where('due_at','<', now());
        }

        return response()->json(
            $q->with(['assignees' => function ($query) {
                  $query->withExists('evidences as has_evidence');
              }, 'assignees.empleado.user'])
              ->withExists('evidences as has_evidence')
              ->orderByDesc('created_at')
              ->paginate(20)
        );
    }

    public function store(Request $request)
    {
        $u = $request->user();
        if (!in_array($u->role, ['admin','supervisor'])) {
            return response()->json(['message'=>'No autorizado'], 403);
        }

        $data = $request->validate([
            'title' => ['required','string','max:180'],
            'description' => ['nullable','string'],
            'priority' => ['nullable', Rule::in(['low','medium','high','urgent'])],
            'due_at' => ['nullable','date'],
            'catalog_date' => ['nullable','date'],
            'estimated_minutes' => ['nullable','integer','min:1','max:1440'],
        ]);

        $meta = [];

        if (!empty($data['catalog_date'])) {
            $meta['catalog_date'] = $data['catalog_date'];
        }
        if (!empty($data['estimated_minutes'])) {
            $meta['estimated_minutes'] = $data['estimated_minutes'];
        }
        $meta['source'] = 'adhoc';

        $task = Task::create([
            'empresa_id' => $u->empresa_id,
            'created_by' => $u->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'priority' => $data['priority'] ?? 'medium',
            'status' => 'open',
            'due_at' => $data['due_at'] ?? null,
            'meta' => empty($meta) ? null : $meta,
        ]);

        return response()->json(['item'=>$this->presentTask($task)], 201);
    }

    public function show(Request $request, string $id)
    {
        $u = $request->user();
        if (!in_array($u->role, ['admin','supervisor'])) {
            return response()->json(['message'=>'No autorizado'], 403);
        }

        $task = Task::where('empresa_id', $u->empresa_id)->where('id', $id)->first();
        if (!$task) return response()->json(['message'=>'No encontrado'], 404);

        $assignees = TaskAssignee::where('empresa_id',$u->empresa_id)
            ->where('task_id',$task->id)
            ->get();

        return response()->json([
            'item' => $this->presentTask($task),
            'assignees' => $assignees->map(fn($a)=>$this->presentAssignee($a)),
        ]);
    }

    public function assign(Request $request, string $id)
    {
        $u = $request->user();
        if (!in_array($u->role, ['admin','supervisor'])) {
            return response()->json(['message'=>'No autorizado'], 403);
        }

        $data = $request->validate([
            'empleado_ids' => ['required','array','min:1'],
            'empleado_ids.*' => ['uuid'],
        ]);

        $task = Task::where('empresa_id', $u->empresa_id)->where('id', $id)->first();
        if (!$task) return response()->json(['message'=>'No encontrado'], 404);

        $validEmployees = Empleado::where('empresa_id', $u->empresa_id)
            ->whereIn('id', $data['empleado_ids'])
            ->pluck('id')
            ->all();

        if (count($validEmployees) !== count($data['empleado_ids'])) {
            return response()->json(['message'=>'Uno o más empleados no pertenecen a esta empresa'], 422);
        }

        DB::transaction(function () use ($u, $task, $validEmployees) {
            foreach ($validEmployees as $empId) {
                TaskAssignee::firstOrCreate(
                    ['empresa_id'=>$u->empresa_id, 'task_id'=>$task->id, 'empleado_id'=>$empId],
                    ['status'=>'assigned']
                );
            }
        });

        if ($task->status === 'open') {
            $task->status = 'in_progress';
            $task->save();
        }

        return response()->json(['message'=>'Asignación OK']);
    }

    public function myTasks(Request $request)
    {
        [$u, $empresaId, $emp] = $this->authEmployee($request);

        $q = Task::where('empresa_id', $empresaId)
            ->whereHas('assignees', fn ($a) =>
                $a->where('empleado_id', $emp->id)
            );

        if ($request->filled('status')) {
            $q->whereIn('status', explode(',', $request->string('status')));
        }

        if ($request->filled('date')) {
            $q->whereRaw("meta->>'catalog_date' = ?", [$request->string('date')]);
        }

        if ($request->filled('search')) {
            $s = $request->string('search');
            $q->where('title','ilike',"%{$s}%");
        }

        return response()->json(
            $q->with('assignees.empleado.user')
              ->withExists('evidences as has_evidence')
              ->orderByDesc('created_at')
              ->paginate(20)
        );
    }

    public function myAssignments(Request $request)
    {
        [$u, $empresaId, $emp] = $this->authEmployee($request);

        $q = TaskAssignee::where('empresa_id', $empresaId)
            ->where('empleado_id', $emp->id)
            ->with(['task' => function ($t) use ($empresaId) {
                $t->where('empresa_id', $empresaId)
                  ->with('assignees.empleado.user')
                  ->withExists('evidences as has_evidence');
            }]);

        if ($request->filled('status')) {
            $q->whereIn('status', explode(',', $request->string('status')));
        }

        if ($request->filled('date')) {
            $date = $request->string('date');
            $q->whereHas('task', fn($t) =>
                $t->whereRaw("meta->>'catalog_date' = ?", [$date])
            );
        }

        if ($request->filled('search')) {
            $s = $request->string('search');
            $q->whereHas('task', fn($t) =>
                $t->where('title','ilike',"%{$s}%")
            );
        }

        $pag = $q->orderByDesc('created_at')->paginate(20);

        // --- EVIDENCIAS RESUMEN POR ASIGNACIÓN (para UI) ---
        $assignmentIds = collect($pag->items())->pluck('id')->filter()->values()->all();

        $evidenceAgg = [];
        $latestEvidenceByAssignee = [];

        if (!empty($assignmentIds)) {
            // Conteo de evidencias por asignación
            $counts = Evidence::where('empresa_id', $empresaId)
                ->whereIn('task_assignee_id', $assignmentIds)
                ->select('task_assignee_id', DB::raw('count(*) as c'))
                ->groupBy('task_assignee_id')
                ->get();

            foreach ($counts as $row) {
                $evidenceAgg[$row->task_assignee_id] = (int) $row->c;
            }

            // Obtener la evidencia más reciente por asignación (usando id::text para UUIDs)
            $latestEvidences = Evidence::where('empresa_id', $empresaId)
                ->whereIn('task_assignee_id', $assignmentIds)
                ->whereIn(DB::raw('id::text'), function ($sub) use ($empresaId, $assignmentIds) {
                    $sub->select(DB::raw('max(id::text)'))
                        ->from('evidences')
                        ->where('empresa_id', $empresaId)
                        ->whereIn('task_assignee_id', $assignmentIds)
                        ->groupBy('task_assignee_id');
                })
                ->get();

            foreach ($latestEvidences as $ev) {
                $latestEvidenceByAssignee[$ev->task_assignee_id] = $ev;
            }
        }

        $pag->getCollection()->transform(function($a) use ($evidenceAgg, $latestEvidenceByAssignee, $empresaId) {
            $assId = $a->id;
            $count = $evidenceAgg[$assId] ?? 0;
            $hasEvidence = $count > 0;
            $latestUrl = null;

            if ($hasEvidence && isset($latestEvidenceByAssignee[$assId])) {
                $latestUrl = $this->evidenceFileUrl($latestEvidenceByAssignee[$assId]);
            }

            // --- CHECKLIST CONTEXT FOR UI ---
            $checklistDef = $checklistState = $checklistProgress = null;
            $tplId = data_get($a->task->meta, 'template_id');

            if ($tplId && $tpl = \App\Models\TaskTemplate::where('empresa_id', $empresaId)
                ->where('id', $tplId)
                ->first()
            ) {
                $ins = $tpl->instructions ?? [];
                if (is_array($ins) && ($ins['type'] ?? null) === 'checklist') {
                    $checklistDef = $ins['items'] ?? [];
                    $checklistState = data_get($a->meta, 'checklist', []);
                    
                    $required = array_filter($checklistDef, fn($i) => ($i['required'] ?? false) === true);
                    $doneCount = array_reduce($required, function($carry, $item) use ($checklistState) {
                        return $carry + (data_get($checklistState, "{$item['id']}.done", false) ? 1 : 0);
                    }, 0);
                    
                    $checklistProgress = [
                        'required_done' => $doneCount,
                        'required_total' => count($required),
                    ];
                }
            }

            return [
                'assignment' => [
                    'id' => $a->id,
                    'task_id' => $a->task_id,
                    'empleado_id' => $a->empleado_id,
                    'status' => $a->status,
                    'started_at' => $a->started_at?->toISOString(),
                    'done_at' => $a->done_at?->toISOString(),
                    'reviewed_at' => $a->reviewed_at?->toISOString(),
                    'review_note' => $a->review_note,
                    'note' => $a->note,

                    // ✅ NUEVOS CAMPOS PARA LA UI
                    'has_evidence' => $hasEvidence,
                    'evidence_count' => $count,
                    'latest_evidence_url' => $latestUrl,
                ],
                'task' => array_merge($this->presentTask($a->task), [
                    'id' => $a->task->id, // redundant but kept for safety if presentTask changes
                ]),
                'checklist_def' => $checklistDef,
                'checklist_state' => $checklistState,
                'checklist_progress' => $checklistProgress,
            ];
        });

        return response()->json([
            'data' => $pag->items(),
            'total' => $pag->total(),
            'last_page' => $pag->lastPage(),
        ]);
    }
    
    // 🔥 NUEVO: Actualiza un item del checklist
    public function updateMyChecklistItem(Request $request, string $assignmentId)
    {
        $u = $request->user();
        if ($u->role !== 'empleado') {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $emp = Empleado::where('empresa_id', $u->empresa_id)
            ->where('user_id', $u->id)
            ->first();
        if (!$emp) return response()->json(['message' => 'Empleado no vinculado'], 404);

        $a = TaskAssignee::where('empresa_id', $u->empresa_id)
            ->where('id', $assignmentId)
            ->where('empleado_id', $emp->id)
            ->with('task')
            ->first();
        if (!$a) return response()->json(['message' => 'Asignación no encontrada'], 404);

        $data = $request->validate([
            'item_id' => ['required', 'string', 'max:80'],
            'done' => ['required', 'boolean'],
        ]);

        // Fetch checklist definition ONLY from template (Task has no instructions column)
        $tplId = data_get($a->task->meta, 'template_id');
        if (!$tplId) {
            return response()->json(['message' => 'Esta tarea no tiene checklist'], 422);
        }

        $tpl = \App\Models\TaskTemplate::where('empresa_id', $u->empresa_id)
            ->where('id', $tplId)
            ->first();
        $instructions = $tpl?->instructions ?? [];

        if (!is_array($instructions) || ($instructions['type'] ?? null) !== 'checklist') {
            return response()->json(['message' => 'Esta tarea no tiene checklist'], 422);
        }

        $items = $instructions['items'] ?? [];
        $validIds = array_column($items, 'id');
        if (!in_array($data['item_id'], $validIds, true)) {
            return response()->json(['message' => 'Item inválido'], 422);
        }

        // Update checklist state in assignee meta
        $meta = $a->meta ?? [];
        $meta['checklist'][$data['item_id']] = [
            'done' => $data['done'],
            'at' => now()->toISOString(),
        ];
        $a->meta = $meta;

        // Auto-mark assignment as in_progress on first interaction
        if (!$a->started_at) {
            $a->started_at = now();
        }
        $a->save();

        $this->recomputeTaskStatus($u->empresa_id, $a->task_id, $u, $request);

        return response()->json([
            'message' => 'OK',
            'assignment' => $this->presentAssignee($a),
            'checklist' => $meta['checklist'],
        ]);
    }

    // 🔥 ACTUALIZADO: usa done_pending como estado de entrega + validación de checklist
    public function updateMyAssignment(Request $request, string $assignmentId)
    {
        $u = $request->user();
        if ($u->role !== 'empleado') {
            return response()->json(['message'=>'No autorizado'], 403);
        }

        $emp = Empleado::where('empresa_id', $u->empresa_id)
            ->where('user_id', $u->id)
            ->first();
        if (!$emp) return response()->json(['message'=>'Empleado no vinculado'], 404);

        $a = TaskAssignee::where('empresa_id', $u->empresa_id)
            ->where('id', $assignmentId)
            ->where('empleado_id', $emp->id)
            ->with('task')
            ->first();

        if (!$a) return response()->json(['message'=>'Asignación no encontrada'], 404);

        $data = $request->validate([
            'status' => ['required', Rule::in(['assigned','in_progress','done_pending'])],
            'note' => ['nullable','string','max:2000'],
        ]);

        if ($data['status'] === 'in_progress' && !$a->started_at) {
            $a->started_at = now();
        }

        if ($data['status'] === 'done_pending') {
            if (!$a->started_at) {
                $a->started_at = now();
            }

            $hasEvidence = Evidence::where('empresa_id', $u->empresa_id)
                ->where('task_assignee_id', $a->id)
                ->exists();

            if (!$hasEvidence) {
                return response()->json(['message' => 'Debes subir evidencia antes de entregar la tarea.'], 422);
            }

            // ✅ BLOCK DELIVERY IF REQUIRED CHECKLIST ITEMS ARE INCOMPLETE
            $tplId = data_get($a->task->meta, 'template_id');
            if ($tplId) {
                $tpl = \App\Models\TaskTemplate::where('empresa_id', $u->empresa_id)
                    ->where('id', $tplId)
                    ->first();
                $instructions = $tpl?->instructions ?? [];

                if (is_array($instructions) && ($instructions['type'] ?? null) === 'checklist') {
                    $requiredItems = array_filter(
                        $instructions['items'] ?? [],
                        fn($it) => ($it['required'] ?? false) === true
                    );
                    
                    $checklistState = data_get($a->meta, 'checklist', []);
                    $missing = array_filter($requiredItems, function($item) use ($checklistState) {
                        return !data_get($checklistState, "{$item['id']}.done", false);
                    });

                    if (count($missing) > 0) {
                        return response()->json([
                            'message' => 'Debes completar todos los items requeridos del checklist antes de entregar.',
                            'missing_items' => array_column($missing, 'id')
                        ], 422);
                    }
                }
            }

            $a->done_at = now();
        }

        $a->status = $data['status'];
        if (array_key_exists('note', $data)) {
            $a->note = $data['note'];
        }
        $a->save();

        $this->recomputeTaskStatus($u->empresa_id, $a->task_id, $u, $request);

        return response()->json(['message'=>'OK', 'assignment'=>$this->presentAssignee($a)]);
    }

    public function updateStatus(Request $request, string $id)
    {
        $u = $request->user();

        // 🔒 Solo admin puede forzar el estado (y solo a "completed")
        if ($u->role !== 'admin') {
            return response()->json(['message' => 'Solo el administrador puede actualizar el estado de una tarea.'], 403);
        }

        $data = $request->validate([
            'status' => ['required', 'in:completed'],
        ]);

        $task = Task::where('empresa_id', $u->empresa_id)
            ->where('id', $id)
            ->first();

        if (!$task) {
            return response()->json(['message' => 'Tarea no encontrada'], 404);
        }

        // Solo permitimos marcar como "completed" manualmente (útil para tareas sin asignaciones)
        if ($data['status'] === 'completed' && $task->status !== 'completed') {
            $old = $task->status;
            $task->status = 'completed';
            $task->save();

            // 🔔 PARCHE 3: Logging con task_title
            \App\Services\ActivityLogger::log(
                $u->empresa_id,
                $u->id,
                null,
                'task.status_changed',
                'task',
                $task->id,
                [
                    'from'       => $old,
                    'to'         => $task->status,
                    'task_title' => $task->title,   // <-- AGREGADO: título de la tarea
                ],
                $request
            );
        }

        return response()->json(['message' => 'Status actualizado', 'task' => $task]);
    }

    // 🔥 NUEVO: lista asignaciones pendientes de revisión (status = done_pending)
    public function reviewQueue(Request $request)
    {
        $u = $request->user();
        if (!in_array($u->role, ['admin','supervisor'])) {
            return response()->json(['message'=>'No autorizado'], 403);
        }

        $q = TaskAssignee::where('empresa_id', $u->empresa_id)
            ->where('status', 'done_pending')
            ->with(['task','empleado'])
            ->orderByDesc('done_at');

        $pag = $q->paginate(20);

        return response()->json([
            'data' => $pag->items(),
            'total' => $pag->total(),
            'last_page' => $pag->lastPage(),
        ]);
    }

    // 🔥 APROBAR: cambia status a "approved"
    public function approveAssignment(Request $request, string $assignmentId)
    {
        $u = $request->user();
        if (!in_array($u->role, ['admin','supervisor'])) {
            return response()->json(['message'=>'No autorizado'], 403);
        }

        $a = TaskAssignee::where('empresa_id',$u->empresa_id)->where('id',$assignmentId)->first();
        if (!$a) return response()->json(['message'=>'Asignación no encontrada'], 404);

        if ($a->status !== 'done_pending') {
            return response()->json(['message'=>'Solo puedes aprobar asignaciones en done_pending'], 422);
        }

        $a->status = 'approved';
        $a->reviewed_at = now();
        $a->reviewed_by = $u->id;
        $a->review_note = null;
        $a->save();

        $this->recomputeTaskStatus($u->empresa_id, $a->task_id, $u, $request);

        return response()->json(['message'=>'Aprobada ✅']);
    }

    // 🔥 RECHAZAR: cambia status a "rejected" + nota
    public function rejectAssignment(Request $request, string $assignmentId)
    {
        $u = $request->user();
        if (!in_array($u->role, ['admin','supervisor'])) {
            return response()->json(['message'=>'No autorizado'], 403);
        }

        $data = $request->validate([
            'note' => ['required','string','max:2000']
        ]);

        $a = TaskAssignee::where('empresa_id',$u->empresa_id)->where('id',$assignmentId)->first();
        if (!$a) return response()->json(['message'=>'Asignación no encontrada'], 404);

        if ($a->status !== 'done_pending') {
            return response()->json(['message'=>'Solo puedes rechazar asignaciones en done_pending'], 422);
        }

        $a->status = 'rejected';
        $a->reviewed_at = now();
        $a->reviewed_by = $u->id;
        $a->review_note = $data['note'];
        $a->save();

        $this->recomputeTaskStatus($u->empresa_id, $a->task_id, $u, $request);

        return response()->json(['message'=>'Rechazada ❌']);
    }

    // 🔥 EVIDENCIAS: sigue aquí (no lo movimos)
    public function taskEvidences(Request $request, string $id)
    {
        $u = $request->user();
        if (!in_array($u->role, ['admin','supervisor'])) {
            return response()->json(['message'=>'No autorizado'], 403);
        }

        $task = Task::where('empresa_id',$u->empresa_id)->where('id',$id)->first();
        if (!$task) return response()->json(['message'=>'Tarea no encontrada'], 404);

        $evs = Evidence::where('empresa_id',$u->empresa_id)
            ->where('task_id',$task->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($e)=>[
                'id'=>$e->id,
                'task_id'=>$e->task_id,
                'task_assignee_id'=>$e->task_assignee_id,
                'empleado_id'=>$e->empleado_id,
                'original_name'=>$e->original_name,
                'mime'=>$e->mime,
                'size'=>$e->size,
                'created_at'=>$e->created_at?->toISOString(),
                'url'=>$this->evidenceFileUrl($e),
            ]);

        return response()->json(['data'=>$evs]);
    }

    // 🔥 ACTUALIZADO: completada solo si todas están "approved"
    private function recomputeTaskStatus(string $empresaId, string $taskId, $user = null, $request = null): void
    {
        $task = Task::where('empresa_id',$empresaId)->where('id',$taskId)->first();
        if (!$task) return;

        $oldStatus = $task->status;

        $ass = TaskAssignee::where('empresa_id',$empresaId)->where('task_id',$taskId)->get();

        if ($ass->isEmpty()) {
            $task->status = 'open';
        } else {
            $approvedCount = $ass->where('status','approved')->count();
            $task->status = ($approvedCount === $ass->count()) ? 'completed' : 'in_progress';
        }

        $task->save();

        if ($user && $request && $oldStatus !== $task->status) {
            // 🔔 PARCHE 4: Logging con task_title
            \App\Services\ActivityLogger::log(
                $empresaId,
                $user->id,
                $user->role === 'empleado' ? ($user->empleado_id ?? null) : null,
                'task.status_changed',
                'task',
                $task->id,
                [
                    'from'       => $oldStatus,
                    'to'         => $task->status,
                    'task_title' => $task->title,   // <-- AGREGADO: título de la tarea
                ],
                $request
            );
        }
    }

    private function authEmployee(Request $request): array
    {
        $u = $request->user();
        if ($u->role !== 'empleado') {
            abort(403, 'No autorizado');
        }

        $empresaId = $u->empresa_id;
        $emp = Empleado::where('empresa_id', $empresaId)
            ->where('user_id', $u->id)
            ->first();

        if (!$emp) {
            abort(404, 'Empleado no vinculado aún. El admin debe vincular este usuario a un empleado.');
        }

        return [$u, $empresaId, $emp];
    }

    private function presentTask(Task $t): array
    {
        $firstAssignee = $t->relationLoaded('assignees')
            ? $t->assignees->first()
            : null;

        $empleado = null;
        if ($firstAssignee && $firstAssignee->relationLoaded('empleado') && $firstAssignee->empleado) {
            $emp = $firstAssignee->empleado;
            $empleado = [
                'id'         => $emp->id,
                'full_name'  => $emp->full_name,
                'name'       => $emp->name ?? $emp->full_name,
                'avatar_url' => $emp->user?->avatar_url ?? null,
            ];
        }

        return [
            'id'           => $t->id,
            'title'        => $t->title,
            'description'  => $t->description,
            'priority'     => $t->priority,
            'status'       => $t->status,
            'due_at'       => $t->due_at?->toISOString(),
            'meta'         => $t->meta,
            'assignee_name'=> $t->assignee_name,
            'has_evidence' => $t->has_evidence,
            'empleado'     => $empleado,
            'created_by'   => $t->created_by,
            'created_at'   => $t->created_at?->toISOString(),
            'updated_at'   => $t->updated_at?->toISOString(),
        ];
    }

    private function presentAssignee(TaskAssignee $a): array
    {
        return [
            'id'=>$a->id,
            'task_id'=>$a->task_id,
            'empleado_id'=>$a->empleado_id,
            'status'=>$a->status,
            'started_at'=>$a->started_at?->toISOString(),
            'done_at'=>$a->done_at?->toISOString(),
            'reviewed_at'=>$a->reviewed_at?->toISOString(),
            'review_note'=>$a->review_note,
            'note'=>$a->note,
            'created_at'=>$a->created_at?->toISOString(),
            'updated_at'=>$a->updated_at?->toISOString(),
        ];
    }

    private function evidenceFileUrl(Evidence $evidence): ?string
    {
        if (!$evidence->path) return null;

        if ($evidence->disk === 's3') {
            return \Storage::temporaryUrl($evidence->path, now()->addMinutes(30), [
                'ResponseContentDisposition' => 'inline'
            ]);
        }

        // LOCAL: Storage::url() devuelve "/storage/..."
        $relative = \Storage::url($evidence->path); // "/storage/...."

        $base = config('app.url'); // ej: http://127.0.0.1:8000

        return rtrim($base, '/') . $relative;
    }
}
# Kore — Dashboard Supervisor + Carga de Trabajo: Backend

Stack: Laravel 11 · PostgreSQL · Railway

---

## Resumen

Agregar un endpoint específico para el dashboard del supervisor que devuelva:
- Tareas pendientes de revisión (done_pending)
- Tareas activas con progreso de checklist
- Carga de trabajo por empleado (minutos asignados hoy)

---

## 1. Nuevo endpoint en `DashboardController`

**Archivo:** `app/Http/Controllers/Api/V1/DashboardController.php`

Agregar método `supervisor()`:

```php
// GET /dashboard/supervisor
public function supervisor(Request $request)
{
    $u = $request->user();
    if (!in_array($u->role, ['admin', 'supervisor'])) {
        return response()->json(['message' => 'No autorizado'], 403);
    }

    $empresaId = $u->empresa_id;
    $hoy = now()->toDateString();

    // ── 1. Tareas pendientes de revisión ────────────────────────────────
    $pendingReview = \App\Models\TaskAssignee::where('empresa_id', $empresaId)
        ->where('status', 'done_pending')
        ->with(['task', 'empleado'])
        ->orderByDesc('done_at')
        ->limit(20)
        ->get()
        ->map(fn($a) => [
            'assignment_id' => $a->id,
            'task_id'       => $a->task_id,
            'task_title'    => $a->task?->title,
            'priority'      => $a->task?->priority,
            'empleado_id'   => $a->empleado_id,
            'empleado_name' => $a->empleado?->full_name,
            'done_at'       => $a->done_at?->toISOString(),
            'note'          => $a->note,
        ]);

    // ── 2. Carga de trabajo por empleado ─────────────────────────────────
    // Obtener todos los empleados activos
    $empleados = \App\Models\Empleado::where('empresa_id', $empresaId)
        ->where('status', 'active')
        ->with('user')
        ->get();

    // Obtener asignaciones activas de hoy (assigned o in_progress)
    $activeAssignments = \App\Models\TaskAssignee::where('empresa_id', $empresaId)
        ->whereIn('status', ['assigned', 'in_progress'])
        ->with('task')
        ->get();

    $workload = $empleados->map(function ($emp) use ($activeAssignments) {
        $empAssignments = $activeAssignments->where('empleado_id', $emp->id);

        $totalMinutes = $empAssignments->sum(function ($a) {
            // Leer estimated_minutes del campo meta de la tarea
            $meta = $a->task?->meta ?? [];
            return data_get($meta, 'estimated_minutes', 30); // default 30 min
        });

        $taskCount = $empAssignments->count();

        // Calcular nivel de carga
        $level = match(true) {
            $totalMinutes >= 240 => 'alto',   // 4+ horas
            $totalMinutes >= 120 => 'medio',  // 2-4 horas
            default              => 'bajo',   // menos de 2 horas
        };

        return [
            'empleado_id'    => $emp->id,
            'full_name'      => $emp->full_name,
            'position_title' => $emp->position_title,
            'avatar_url'     => $emp->user?->avatar_url,
            'total_minutes'  => $totalMinutes,
            'total_hours'    => round($totalMinutes / 60, 1),
            'task_count'     => $taskCount,
            'workload_level' => $level, // 'bajo' | 'medio' | 'alto'
            'assignments'    => $empAssignments->map(fn($a) => [
                'assignment_id'     => $a->id,
                'task_id'           => $a->task_id,
                'task_title'        => $a->task?->title,
                'estimated_minutes' => data_get($a->task?->meta ?? [], 'estimated_minutes', 30),
                'status'            => $a->status,
                'progress'          => $this->calcProgress($a, $emp->empresa_id),
            ])->values(),
        ];
    })->values();

    // ── 3. KPIs rápidos del supervisor ───────────────────────────────────
    $totalTasks = \App\Models\Task::where('empresa_id', $empresaId)
        ->whereIn('status', ['open', 'in_progress'])
        ->count();

    $completedToday = \App\Models\Task::where('empresa_id', $empresaId)
        ->where('status', 'completed')
        ->whereDate('updated_at', $hoy)
        ->count();

    return response()->json([
        'data' => [
            'kpi' => [
                'pending_review'  => $pendingReview->count(),
                'active_tasks'    => $totalTasks,
                'completed_today' => $completedToday,
            ],
            'pending_review' => $pendingReview,
            'workload'       => $workload,
        ],
    ]);
}

// Helper privado para calcular progreso de checklist
private function calcProgress(\App\Models\TaskAssignee $a, string $empresaId): array
{
    $tplId = data_get($a->task?->meta ?? [], 'template_id');
    if (!$tplId) {
        return ['type' => 'simple', 'pct' => $a->status === 'approved' ? 100 : 0];
    }

    $tpl = \App\Models\TaskTemplate::where('empresa_id', $empresaId)
        ->where('id', $tplId)
        ->first();

    $instructions = $tpl?->instructions ?? [];
    if (!is_array($instructions) || ($instructions['type'] ?? null) !== 'checklist') {
        return ['type' => 'simple', 'pct' => 0];
    }

    $items = $instructions['items'] ?? [];
    $total = count($items);
    if ($total === 0) return ['type' => 'checklist', 'pct' => 0, 'done' => 0, 'total' => 0];

    $checklistState = data_get($a->meta ?? [], 'checklist', []);
    $done = collect($items)->filter(fn($item) =>
        data_get($checklistState, "{$item['id']}.done", false)
    )->count();

    return [
        'type'  => 'checklist',
        'pct'   => round(($done / $total) * 100),
        'done'  => $done,
        'total' => $total,
    ];
}
```

---

## 2. Ruta en `api.php`

```php
// Agregar junto a la ruta existente /dashboard/manager:
Route::get('/dashboard/supervisor', [DashboardController::class, 'supervisor']);
```

---

## 3. Formato de respuesta completo

```json
{
  "data": {
    "kpi": {
      "pending_review": 3,
      "active_tasks": 12,
      "completed_today": 5
    },
    "pending_review": [
      {
        "assignment_id": "uuid",
        "task_id": "uuid",
        "task_title": "Limpieza de área",
        "priority": "medium",
        "empleado_id": "uuid",
        "empleado_name": "Kevin Alfredo",
        "done_at": "2026-03-31T14:30:00Z",
        "note": "Lista"
      }
    ],
    "workload": [
      {
        "empleado_id": "uuid",
        "full_name": "Juan Pérez",
        "position_title": "Ayudante General",
        "avatar_url": null,
        "total_minutes": 90,
        "total_hours": 1.5,
        "task_count": 3,
        "workload_level": "bajo",
        "assignments": [
          {
            "assignment_id": "uuid",
            "task_id": "uuid",
            "task_title": "Acomodar góndola 3",
            "estimated_minutes": 30,
            "status": "in_progress",
            "progress": {
              "type": "checklist",
              "pct": 66,
              "done": 2,
              "total": 3
            }
          }
        ]
      }
    ]
  }
}
```

---

## 4. Resumen de archivos a modificar

| Archivo | Acción |
|---|---|
| `app/Http/Controllers/Api/V1/DashboardController.php` | Modificar — agregar método `supervisor()` y helper `calcProgress()` |
| `routes/api.php` | Modificar — agregar ruta `GET /dashboard/supervisor` |

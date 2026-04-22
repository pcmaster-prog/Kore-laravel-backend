<?php
//DashboardController: endpoints para datos agregados y KPIs para dashboards de manager y empleado
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\Task;
use App\Models\ActivityLog;
use App\Models\Empleado;
use App\Models\AttendanceDay;

class DashboardController extends Controller
{
    // =========================
    // MANAGER DASHBOARD
    // =========================
    public function manager(Request $request)
    {
        $u = $request->user();

        if (!in_array($u->role, ['admin','supervisor'])) {
            return \App\Support\ApiResponse::error('No autorizado', 403);
        }

        $empresaId = $u->empresa_id;
        $date = $request->input('date', now()->timezone(config('app.timezone'))->toDateString());

        // ---- TASK KPIS
        $tasksBase = Task::where('empresa_id',$empresaId);

        $kpi = [
            'open' => (clone $tasksBase)->where('status','open')->count(),
            'in_progress' => (clone $tasksBase)->where('status','in_progress')->count(),
            'completed' => (clone $tasksBase)->where('status','completed')->count(),
            'overdue' => (clone $tasksBase)
                ->whereNot('status','completed')
                ->whereNotNull('due_at')
                ->where('due_at','<', now())
                ->count(),
        ];

        // tareas de HOY (por catalog_date)
        $todayCounts = [
            'open' => (clone $tasksBase)->whereRaw("meta->>'catalog_date' = ?", [$date])->where('status','open')->count(),
            'in_progress' => (clone $tasksBase)->whereRaw("meta->>'catalog_date' = ?", [$date])->where('status','in_progress')->count(),
            'completed' => (clone $tasksBase)->whereRaw("meta->>'catalog_date' = ?", [$date])->where('status','completed')->count(),
        ];

        $overdueList = (clone $tasksBase)
            ->whereNot('status','completed')
            ->whereNotNull('due_at')
            ->where('due_at','<', now())
            ->orderBy('due_at')
            ->limit(10)
            ->get(['id','title','priority','status','due_at','created_at','meta']);

        // ---- ACTIVITY (últimos 15)
        $activity = ActivityLog::where('empresa_id',$empresaId)
            ->orderByDesc('created_at')
            ->limit(15)
            ->get(['id','action','entity_type','entity_id','meta','user_id','empleado_id','created_at']);

        // ---- ATTENDANCE snapshot de HOY
        $employeesTotal = Empleado::where('empresa_id',$empresaId)->count();

        // Section 2.1: eager load empleado to avoid N+1 if needed downstream
        $attendanceDays = AttendanceDay::where('empresa_id',$empresaId)
            ->where('date',$date)
            ->with('events')
            ->get(['id','empleado_id','status','first_check_in_at','last_check_out_at']);

        $closed = $attendanceDays->whereNotNull('last_check_out_at')->count();
        $checkedIn = $attendanceDays->whereNotNull('first_check_in_at')->count();
        $open = $attendanceDays->whereNotNull('first_check_in_at')->whereNull('last_check_out_at')->count();
        $out = max(0, $employeesTotal - $checkedIn);

        $attendance = [
            'date' => $date,
            'employees_total' => $employeesTotal,
            'checked_in' => $checkedIn,
            'open' => $open,
            'closed' => $closed,
            'out' => $out,
        ];

        return \App\Support\ApiResponse::ok([
            'date' => $date,
            'kpi' => $kpi,
            'today' => $todayCounts,
            'overdue_list' => $overdueList,
            'activity' => $activity,
            'attendance' => $attendance,
        ]);
    }

    // =========================
    // EMPLOYEE DASHBOARD (CORREGIDO)
    // =========================
    public function employee(Request $request)
    {
        $u = $request->user();

        if ($u->role !== 'empleado') {
            return \App\Support\ApiResponse::error('No autorizado', 403);
        }

        $empresaId = $u->empresa_id;

        $emp = Empleado::where('empresa_id',$empresaId)->where('user_id',$u->id)->first();
        if (!$emp) {
            return \App\Support\ApiResponse::error('Empleado no encontrado', 404);
        }

        // ✅ date parametrizable + timezone de app
        $date = $request->input('date');
        if (!$date) {
            $date = now()->timezone(config('app.timezone'))->toDateString();
        }

        $day = AttendanceDay::where('empresa_id',$empresaId)
            ->where('empleado_id',$emp->id)
            ->where('date',$date)
            ->first();

        $attendanceState = $day ? ($day->last_check_out_at ? 'closed' : 'open') : 'out';

        // ✅ Tareas del día por meta.catalog_date
        $tasksToday = Task::where('empresa_id',$empresaId)
            ->whereHas('assignees', fn($a) => $a->where('empleado_id',$emp->id))
            ->whereRaw("meta->>'catalog_date' = ?", [$date])
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['id','title','priority','status','due_at','created_at','meta']);

        // counts (globales del empleado)
        $base = Task::where('empresa_id',$empresaId)
            ->whereHas('assignees', fn($a) => $a->where('empleado_id',$emp->id));

        $counts = [
            'open' => (clone $base)->where('status','open')->count(),
            'in_progress' => (clone $base)->where('status','in_progress')->count(),
            'completed' => (clone $base)->where('status','completed')->count(),
        ];

        return \App\Support\ApiResponse::ok([
            'date' => $date,
            'attendance' => [
                'state' => $attendanceState,
                'day_id' => $day?->id,
            ],
            'counts' => $counts,
            'tasks_today' => $tasksToday,
        ]);
    }

    // =========================
    // SUPERVISOR DASHBOARD
    // =========================
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
        // Section 2.1: eager load user to prevent N+1
        $empleados = \App\Models\Empleado::where('empresa_id', $empresaId)
            ->where('status', 'active')
            ->with('user:id,name,avatar_url')
            ->get();

        // Section 2.1: eager load task to prevent N+1 in the workload loop
        $activeAssignments = \App\Models\TaskAssignee::where('empresa_id', $empresaId)
            ->whereIn('status', ['assigned', 'in_progress'])
            ->with('task:id,title,priority,status,meta')
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
                'assignments'    => $empAssignments->map(fn(\App\Models\TaskAssignee $a) => [
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
}
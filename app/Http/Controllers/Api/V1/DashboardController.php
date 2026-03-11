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

        $attendanceDays = AttendanceDay::where('empresa_id',$empresaId)
            ->where('date',$date)
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
}
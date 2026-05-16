<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

use App\Models\AttendanceDay;
use App\Models\AttendanceEvent;
use App\Models\Empleado;
use App\Models\User;
use App\Models\MealSchedule;
use App\Models\Holiday;
use App\Models\EmployeeCalendarOverride;
use App\Models\AttendanceAbsenceRequest;
use App\Services\AttendanceService;
use App\Services\ActivityLogger;

class AttendanceReportController extends Controller
{
    // ============================================================
    // 1. Cierre Masivo de Jornada
    // ============================================================
    public function cerrarMasivo(Request $request)
    {
        Gate::authorize('supervisor');

        $data = $request->validate([
            'date'   => ['required', 'date_format:Y-m-d'],
            'motivo' => ['required', 'string', 'max:500'],
        ]);

        $u = $request->user();
        $empresaId = $u->empresa_id;
        $date = $data['date'];
        $motivo = $data['motivo'];

        $days = AttendanceDay::where('empresa_id', $empresaId)
            ->where('date', $date)
            ->whereNotNull('first_check_in_at')
            ->whereNull('last_check_out_at')
            ->whereNotIn('status', ['day_off', 'holiday'])
            ->with('empleado')
            ->get();

        if ($days->isEmpty()) {
            return response()->json([
                'message' => 'No hay empleados en turno para cerrar en esta fecha',
            ], 422);
        }

        $closedCount = 0;
        $employeeNames = [];
        $now = now();

        foreach ($days as $day) {
            $day->last_check_out_at = $now;
            $day->status = 'closed';
            $day->admin_closed = true;
            $day->admin_closed_by = $u->id;
            $day->admin_closed_reason = $motivo;
            $day->save();

            $closedCount++;
            $employeeNames[] = $day->empleado?->full_name ?? 'Empleado sin nombre';
        }

        ActivityLogger::log(
            $empresaId,
            $u->id,
            null,
            'attendance.mass_close',
            'attendance_day',
            null,
            [
                'date'          => $date,
                'motivo'        => $motivo,
                'closed_count'  => $closedCount,
                'employee_names'=> $employeeNames,
                'closed_by'     => $u->name,
            ],
            $request
        );

        return response()->json([
            'message'      => "Se cerraron {$closedCount} jornadas correctamente",
            'closed_count' => $closedCount,
            'employees'    => $employeeNames,
        ]);
    }

    // ============================================================
    // 2. Reporte de Asistencia Semanal (Matriz)
    // ============================================================
    public function asistenciaSemanal(Request $request)
    {
        try {
            Gate::authorize('supervisor');

            $data = $request->validate([
                'from'                => ['required', 'date_format:Y-m-d'],
                'to'                  => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
                'empleado_ids'        => ['nullable', 'string'],
                'incluir_retardos'    => ['nullable', 'boolean'],
                'incluir_tiempos_comida' => ['nullable', 'boolean'],
            ]);

            $u = $request->user();
            $empresaId = $u->empresa_id;
            $from = Carbon::parse($data['from']);
            $to = Carbon::parse($data['to']);

            $incluirRetardos = filter_var($request->input('incluir_retardos', false), FILTER_VALIDATE_BOOLEAN);
            $incluirComida = filter_var($request->input('incluir_tiempos_comida', false), FILTER_VALIDATE_BOOLEAN);

            // Empleados a consultar
            $empleadoQuery = Empleado::where('empresa_id', $empresaId)
                ->whereHas('user', function ($q) {
                    $q->where('is_active', true)->where('role', 'empleado');
                });

            if (!empty($data['empleado_ids'])) {
                $ids = array_filter(explode(',', $data['empleado_ids']));
                $empleadoQuery->whereIn('id', $ids);
            }

            $empleados = $empleadoQuery->with('user')->get();

            // Validar que los empleado_ids pertenezcan a la empresa
            if (!empty($data['empleado_ids'])) {
                $ids = array_filter(explode(',', $data['empleado_ids']));
                $countValid = Empleado::where('empresa_id', $empresaId)->whereIn('id', $ids)->count();
                if ($countValid !== count($ids)) {
                    return response()->json(['message' => 'Uno o más empleados no pertenecen a tu empresa'], 403);
                }
            }

            // Precargar datos globales de la empresa para el rango
            $holidays = Holiday::where('empresa_id', $empresaId)
                ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
                ->pluck('name', 'date')
                ->toArray();

            $mealSchedules = MealSchedule::where('empresa_id', $empresaId)
                ->get()
                ->keyBy('employee_id');

            $filas = [];

            foreach ($empleados as $emp) {
                $fila = $this->buildEmployeeWeeklyRow(
                    $emp,
                    $from,
                    $to,
                    $empresaId,
                    $holidays,
                    null,
                    null,
                    $mealSchedules,
                    $incluirRetardos,
                    $incluirComida
                );
                $filas[] = $fila;
            }

            return response()->json([
                'semana' => (int) $from->format('W'),
                'anio'   => (int) $from->year,
                'rango'  => [
                    'desde' => $from->toDateString(),
                    'hasta' => $to->toDateString(),
                ],
                'filas'  => $filas,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('ERROR asistenciaSemanal: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'message' => 'Error interno: ' . $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ], 500);
        }
    }

    // ============================================================
    // 3. Reporte por Empleado
    // ============================================================
    public function reporteEmpleado(Request $request, string $empleadoId)
    {
        Gate::authorize('supervisor');

        $data = $request->validate([
            'from'                => ['required', 'date_format:Y-m-d'],
            'to'                  => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'incluir_retardos'    => ['nullable', 'boolean'],
            'incluir_tiempos_comida' => ['nullable', 'boolean'],
        ]);

        $u = $request->user();
        $empresaId = $u->empresa_id;
        $from = Carbon::parse($data['from']);
        $to = Carbon::parse($data['to']);

        $incluirRetardos = filter_var($request->input('incluir_retardos', false), FILTER_VALIDATE_BOOLEAN);
        $incluirComida = filter_var($request->input('incluir_tiempos_comida', false), FILTER_VALIDATE_BOOLEAN);

        $emp = Empleado::where('empresa_id', $empresaId)->where('id', $empleadoId)->first();
        if (!$emp) {
            return response()->json(['message' => 'Empleado no encontrado'], 404);
        }

        if ($emp->empresa_id !== $empresaId) {
            return response()->json(['message' => 'No tienes permisos para realizar esta acción'], 403);
        }

        // Precargar datos
        $attendanceDays = AttendanceDay::where('empresa_id', $empresaId)
            ->where('empleado_id', $emp->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->keyBy(fn($d) => $d->date->toDateString());

        $holidays = Holiday::where('empresa_id', $empresaId)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->pluck('name', 'date')
            ->toArray();

        $overrides = EmployeeCalendarOverride::where('empresa_id', $empresaId)
            ->where('empleado_id', $emp->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->where('type', 'rest')
            ->pluck('type', 'date')
            ->toArray();

        $absenceRequests = AttendanceAbsenceRequest::where('empresa_id', $empresaId)
            ->where('empleado_id', $emp->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->where('status', 'approved')
            ->get()
            ->keyBy(fn($ar) => $ar->date->toDateString());

        $mealSchedule = MealSchedule::where('empresa_id', $empresaId)
            ->where('employee_id', $emp->id)
            ->first();

        $detalle = [];
        $resumen = [
            'dias_trabajados'         => 0,
            'dias_faltas'             => 0,
            'dias_descanso'           => 0,
            'total_horas'             => 0,
            'total_retardos'          => 0,
            'promedio_comida_minutos' => 0,
        ];
        $comidaMinutosList = [];

        $period = CarbonPeriod::create($from, $to);
        foreach ($period as $date) {
            $dateStr = $date->toDateString();
            $day = $attendanceDays->get($dateStr);

            $estado = $this->determinarEstadoDia(
                $day,
                $dateStr,
                $empresaId,
                $emp->id,
                $holidays,
                $overrides,
                $absenceRequests
            );

            $horasTrabajadas = 0;
            $tiempoComida = 0;
            $retardosMinutos = null;

            if ($day) {
                $totals = AttendanceService::computeDayTotals($day);
                $horasTrabajadas = $totals['worked_minutes'];

                if ($incluirRetardos && $day->late_minutes > 0) {
                    $retardosMinutos = $day->late_minutes;
                }
            }

            if ($incluirComida) {
                $tiempoComida = $this->calcularTiempoComida($day, $mealSchedule);
            }

            // Acumular resumen
            if (in_array($estado, ['presente', 'retardo'])) {
                $resumen['dias_trabajados']++;
            } elseif ($estado === 'falta') {
                $resumen['dias_faltas']++;
            } elseif ($estado === 'descanso') {
                $resumen['dias_descanso']++;
            }

            $resumen['total_horas'] += $horasTrabajadas;

            if ($day && $day->late_minutes > 0) {
                $resumen['total_retardos']++;
            }

            if ($tiempoComida > 0) {
                $comidaMinutosList[] = $tiempoComida;
            }

            $detalle[] = [
                'fecha'               => $dateStr,
                'entrada'             => $day?->first_check_in_at?->toISOString(),
                'salida'              => $day?->last_check_out_at?->toISOString(),
                'estado'              => $estado,
                'horas_trabajadas'    => $horasTrabajadas,
                'tiempo_comida_minutos' => $tiempoComida,
                'retardos_minutos'    => $retardosMinutos,
            ];
        }

        $resumen['promedio_comida_minutos'] = count($comidaMinutosList) > 0
            ? (int) round(array_sum($comidaMinutosList) / count($comidaMinutosList))
            : 0;

        return response()->json([
            'empleado' => [
                'id'             => $emp->id,
                'nombre'         => $emp->full_name,
                'position_title' => $emp->position_title,
                'hired_at'       => $emp->hired_at?->toDateString(),
            ],
            'periodo' => [
                'desde' => $from->toDateString(),
                'hasta' => $to->toDateString(),
            ],
            'resumen' => $resumen,
            'detalle' => $detalle,
        ]);
    }

    // ============================================================
    // Helpers privados
    // ============================================================

    private function buildEmployeeWeeklyRow(
        Empleado $emp,
        Carbon $from,
        Carbon $to,
        string $empresaId,
        array $holidays,
        $overrides,
        $absenceRequests,
        $mealSchedules,
        bool $incluirRetardos,
        bool $incluirComida
    ): array {
        $attendanceDays = AttendanceDay::where('empresa_id', $empresaId)
            ->where('empleado_id', $emp->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->keyBy(fn($d) => $d->date->toDateString());

        $empOverrides = EmployeeCalendarOverride::where('empresa_id', $empresaId)
            ->where('empleado_id', $emp->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->where('type', 'rest')
            ->get()
            ->groupBy(fn($ov) => $ov->date->toDateString());

        $empAbsences = AttendanceAbsenceRequest::where('empresa_id', $empresaId)
            ->where('empleado_id', $emp->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->where('status', 'approved')
            ->get()
            ->groupBy(fn($ar) => $ar->date->toDateString());

        $mealSchedule = $mealSchedules->get($emp->id);

        $dias = [];
        $totalHoras = 0;
        $totalRetardos = 0;

        // Generar SIEMPRE domingo a sábado; los fuera del rango son null
        $nombresDias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

        // Determinar el domingo de la semana que contiene a $from
        $weekStart = $from->copy()->startOfWeek(0); // 0 = Sunday
        $weekEnd = $weekStart->copy()->addDays(6);

        for ($i = 0; $i <= 6; $i++) {
            $currentDate = $weekStart->copy()->addDays($i);
            $currentDateStr = $currentDate->toDateString();
            $nombreDia = $nombresDias[$i];

            // Si está fuera del rango solicitado → null
            if ($currentDate->lt($from) || $currentDate->gt($to)) {
                $dias[$nombreDia] = null;
                continue;
            }

            $day = $attendanceDays->get($currentDateStr);

            $estado = $this->determinarEstadoDia(
                $day,
                $currentDateStr,
                $empresaId,
                $emp->id,
                $holidays,
                $empOverrides,
                $empAbsences
            );

            $horasTrabajadas = 0;
            $tiempoComida = 0;

            if ($day) {
                $totals = AttendanceService::computeDayTotals($day);
                $horasTrabajadas = $totals['worked_minutes'];

                if ($day->late_minutes > 0) {
                    $totalRetardos++;
                }
            }

            if ($incluirComida) {
                $tiempoComida = $this->calcularTiempoComida($day, $mealSchedule);
            }

            $totalHoras += $horasTrabajadas;

            $dias[$nombreDia] = [
                'dia'                   => $nombreDia,
                'fecha'                 => $currentDateStr,
                'entrada'               => $day?->first_check_in_at?->toISOString(),
                'salida'                => $day?->last_check_out_at?->toISOString(),
                'estado'                => $estado,
                'horas_trabajadas'      => $horasTrabajadas,
                'tiempo_comida_minutos' => $tiempoComida,
            ];
        }

        return [
            'empleado' => [
                'id'             => $emp->id,
                'nombre'         => $emp->full_name,
                'comida_hora'    => $mealSchedule?->meal_start_time,
                'position_title' => $emp->position_title,
            ],
            'dias'          => $dias,
            'total_horas'   => $totalHoras,
            'total_retardos'=> $totalRetardos,
        ];
    }

    private function determinarEstadoDia(
        ?AttendanceDay $day,
        string $date,
        string $empresaId,
        string $empleadoId,
        array $holidays,
        $overrides,
        $absenceRequests
    ): string {
        // 1. Ausencias aprobadas (vacaciones / incapacidad)
        $absences = $absenceRequests instanceof \Illuminate\Support\Collection
            ? $absenceRequests->get($date, collect())
            : ($absenceRequests[$date] ?? collect());

        if ($absences instanceof \Illuminate\Support\Collection && $absences->isNotEmpty()) {
            $motivo = strtolower($absences->first()->motivo ?? '');
            if (str_contains($motivo, 'vacacion')) {
                return 'vacaciones';
            }
            if (str_contains($motivo, 'incapacidad') || str_contains($motivo, 'enfermedad')) {
                return 'incapacidad';
            }
        } elseif (!empty($absences)) {
            $motivo = strtolower($absences->motivo ?? '');
            if (str_contains($motivo, 'vacacion')) {
                return 'vacaciones';
            }
            if (str_contains($motivo, 'incapacidad') || str_contains($motivo, 'enfermedad')) {
                return 'incapacidad';
            }
        }

        // 2. Festivo
        if (isset($holidays[$date])) {
            return 'festivo';
        }

        // 3. Descanso (override o day_off)
        $ov = $overrides instanceof \Illuminate\Support\Collection
            ? $overrides->get($date)
            : ($overrides[$date] ?? null);

        if ($ov || ($day && $day->status === 'day_off')) {
            return 'descanso';
        }

        // 4. Si no hay registro de asistencia → falta
        if (!$day) {
            return 'falta';
        }

        // 5. Si es holiday en el status del día
        if ($day->status === 'holiday') {
            return 'festivo';
        }

        // 6. Sin entrada → falta
        if ($day->first_check_in_at === null) {
            return 'falta';
        }

        // 7. Con entrada y salida → presente (o retardo)
        if ($day->last_check_out_at !== null) {
            if ($day->late_minutes > 0) {
                return 'retardo';
            }
            return 'presente';
        }

        // 8. Con entrada pero sin salida → en_turno (o retardo)
        if ($day->late_minutes > 0) {
            return 'retardo';
        }
        return 'en_turno';
    }

    private function calcularTiempoComida(?AttendanceDay $day, ?MealSchedule $mealSchedule): int
    {
        if ($day && $day->lunch_start_at && $day->lunch_end_at) {
            return (int) round($day->lunch_start_at->diffInMinutes($day->lunch_end_at));
        }

        if ($day && $day->lunch_start_at && !$day->lunch_end_at) {
            return (int) round($day->lunch_start_at->diffInMinutes(now()));
        }

        if ($mealSchedule) {
            return (int) $mealSchedule->duration_minutes;
        }

        return 0;
    }
}

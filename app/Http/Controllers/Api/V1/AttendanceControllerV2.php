<?php
// AttendanceControllerV2: manejo de asistencia (check-in, check-out, pausas) con lógica de estados, eventos y cálculo de totales.
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

use App\Models\AttendanceDay;
use App\Models\AttendanceEvent;
use App\Models\Empleado;
use App\Models\EmployeeCalendarOverride;
use App\Models\Empresa;
use App\Models\Holiday;
use App\Models\LateArrivalRequest;
use App\Services\NotificationService;
use App\Services\AttendanceService;
use App\Http\Resources\AttendanceDayResource;
use App\Jobs\SendPushNotification;
use App\Jobs\SendPushNotificationToManagers;
use App\Events\AttendanceCheckedIn;

class AttendanceControllerV2 extends Controller
{
    // EMPLEADO: entrada
    public function checkIn(Request $request)
    {
        [$u, $empresaId, $emp] = $this->authEmployee($request);
        $empresa = Empresa::find($empresaId);

        $today = now()->toDateString();

        // 🛡️ VALIDACIÓN: No permitir entrada antes de la hora configurada (-15 min margen)
        // ni después de la hora de salida configurada
        $daySchedule = AttendanceService::getDaySchedule($empresaId, $today);
        if ($daySchedule) {
            if (!$daySchedule['is_working_day']) {
                return response()->json([
                    'message' => 'Hoy no es día laborable según el horario configurado.',
                    'code' => 'NON_WORKING_DAY',
                ], 409);
            }
        }

        // Hora de entrada efectiva: la del empleado o fallback al horario de empresa
        $effectiveCheckInTime = AttendanceService::getEmployeeCheckInTime($empresaId, $emp->id, $today);

        if ($effectiveCheckInTime) {
            $todayCheckIn  = \Carbon\Carbon::parse($today . ' ' . $effectiveCheckInTime);
            $todayEarliest = $todayCheckIn->copy()->subMinutes(15);
            $now = now();

            if ($now->lessThan($todayEarliest)) {
                return response()->json([
                    'message' => "Entrada bloqueada: Aún es muy temprano. El acceso para las " . $todayCheckIn->format('H:i') . " se permite desde las " . $todayEarliest->format('H:i') . ". Tu hora actual es: " . $now->format('H:i'),
                    'code'                => 'CHECK_IN_TOO_EARLY',
                    'earliest_allowed'    => $todayEarliest->toTimeString(),
                    'current_server_time' => $now->toTimeString(),
                ], 409);
            }

            // Bloqueo por llegada tarde (sin oportunidad aprobada)
            $tardinessConfig = \App\Models\TardinessConfig::firstOrCreate(
                ['empresa_id' => $empresaId],
                [
                    'grace_period_minutes'    => 10,
                    'late_threshold_minutes'  => 1,
                    'lates_to_absence'        => 3,
                    'accumulation_period'     => 'month',
                    'penalize_rest_day'       => true,
                    'notify_employee_on_late' => true,
                    'notify_manager_on_late'  => true,
                ]
            );

            $lateWindowClosesAt = $todayCheckIn->copy()
                ->addMinutes($tardinessConfig->grace_period_minutes)
                ->addMinutes($tardinessConfig->late_threshold_minutes);

            if ($now->greaterThan($lateWindowClosesAt)) {
                $hasApproved = LateArrivalRequest::where('empresa_id', $empresaId)
                    ->where('empleado_id', $emp->id)
                    ->whereDate('date', $today)
                    ->where('status', 'approved')
                    ->exists();

                if (! $hasApproved) {
                    \App\Services\ActivityLogger::log(
                        $empresaId,
                        $u->id,
                        $emp->id,
                        'attendance.check_in_blocked',
                        'attendance_day',
                        null,
                        [
                            'employee_name' => $emp->full_name ?? $u->name,
                            'scheduled_time' => $effectiveCheckInTime,
                            'late_window_closes_at' => $lateWindowClosesAt->toTimeString(),
                            'current_server_time' => $now->toTimeString(),
                        ],
                        $request
                    );

                    try {
                        SendPushNotificationToManagers::dispatch(
                            $empresaId,
                            '🚫 Entrada bloqueada por retardo',
                            ($emp->full_name ?? $u->name) . ' intentó marcar entrada a las ' . $now->format('H:i') . ' (hora límite: ' . $lateWindowClosesAt->format('H:i') . ').',
                            [
                                'type' => 'attendance.check_in_blocked',
                                'empleado_id' => $emp->id,
                            ]
                        );
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::error('Error notificando bloqueo de entrada: ' . $e->getMessage());
                    }

                    return response()->json([
                        'message' => 'Llegaste tarde. No puedes registrar tu entrada. Debes regresar, ya no tienes permitido trabajar hoy. Solicita una oportunidad a tu administrador.',
                        'code' => 'CHECK_IN_LATE_BLOCKED',
                        'scheduled_time' => $effectiveCheckInTime,
                        'late_window_closes_at' => $lateWindowClosesAt->toTimeString(),
                        'current_server_time' => $now->toTimeString(),
                    ], 409);
                }
            }
        }

        // Validación de hora de salida (horario de empresa)
        $checkOutTimeStr = $daySchedule['check_out_time'] ?? null;
        if ($checkOutTimeStr) {
            $todayCheckOut = \Carbon\Carbon::parse($today . ' ' . $checkOutTimeStr);
            $now = now();

            if ($now->greaterThan($todayCheckOut)) {
                return response()->json([
                    'message' => "Entrada bloqueada: El turno ya terminó. La hora de salida era las " . $todayCheckOut->format('H:i') . ". Tu hora actual es: " . $now->format('H:i'),
                    'code'                => 'CHECK_IN_TOO_LATE',
                    'latest_allowed'      => $todayCheckOut->toTimeString(),
                    'current_server_time' => $now->toTimeString(),
                ], 409);
            }
        }

        if (!$this->validateNetworkAccess($request, $empresa)) {
            return response()->json([
                'message' => 'No puedes marcar asistencia fuera de la red de la tienda.',
                'code' => 'NETWORK_RESTRICTED'
            ], 403);
        }

        if (AttendanceService::isRestDay($empresaId, $emp->id, $today)) {
            return response()->json(['message'=>'Hoy es día de descanso'], 409);
        }

        $isHoliday = Holiday::where('empresa_id', $empresaId)
            ->whereDate('date', $today)
            ->exists();

        if ($isHoliday) {
            return response()->json([
                'message' => 'Hoy es día festivo. No se requiere asistencia.',
                'code' => 'HOLIDAY',
            ], 422);
        }

        $day = AttendanceDay::firstOrCreate(
            ['empresa_id'=>$empresaId, 'empleado_id'=>$emp->id, 'date'=>$today],
            ['status'=>'open']
        );

        $state = $this->currentState($day);

        if ($state !== 'out') {
            return response()->json(['message'=>'No puedes marcar entrada ahora', 'state'=>$state], 409);
        }

        $this->addEvent($empresaId, $day->id, 'check_in', $request);

        $day->first_check_in_at = $day->first_check_in_at ?? now();
        $day->status = 'open';

        // ⏰ DETECCIÓN DE RETARDO (via TardinessConfig)
        $lateMinutes = 0;
        $tardCount   = 0;

        $tardinessConfig = \App\Models\TardinessConfig::firstOrCreate(
            ['empresa_id' => $empresaId],
            [
                'grace_period_minutes'    => 10,
                'late_threshold_minutes'  => 1,
                'lates_to_absence'        => 3,
                'accumulation_period'     => 'month',
                'penalize_rest_day'       => true,
                'notify_employee_on_late' => true,
                'notify_manager_on_late'  => true,
            ]
        );

        $effectiveCheckInTimeForLate = AttendanceService::getEmployeeCheckInTime($empresaId, $emp->id, $today);

        if ($effectiveCheckInTimeForLate) {
            $scheduledTime = \Carbon\Carbon::parse($today . ' ' . $effectiveCheckInTimeForLate);
            $graceLimit    = $scheduledTime->copy()->addMinutes($tardinessConfig->grace_period_minutes);
            $lateThreshold = $graceLimit->copy()->addMinutes($tardinessConfig->late_threshold_minutes);
            $nowTs         = now();

            if ($nowTs->greaterThan($lateThreshold)) {
                $lateMinutes = (int) ceil(abs($nowTs->diffInMinutes($scheduledTime)));
            }
        }

        if ($lateMinutes > 0) {
            $day->late_minutes = $lateMinutes;
            $day->status = 'late'; // ← marcar retardo en DB para que el admin lo vea
        }
        $day->save();

        event(new AttendanceCheckedIn($day));

        // Contar retardos acumulados del mes (incluido el de hoy si aplica)
        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd   = now()->endOfMonth()->toDateString();
        $tardCount  = AttendanceDay::where('empresa_id', $empresaId)
            ->where('empleado_id', $emp->id)
            ->whereBetween('date', [$monthStart, $monthEnd])
            ->where('late_minutes', '>', 0)
            ->count();

        // Generar ausencia si se alcanzó el umbral de retardos
        if ($tardCount >= $tardinessConfig->lates_to_absence
            && $tardinessConfig->penalize_rest_day) {
            \App\Models\GeneratedAbsence::firstOrCreate(
                [
                    'empleado_id' => $emp->id,
                    'period_key'  => now()->format('Y-m'),
                    'type'        => 'late_accumulation',
                ],
                [
                    'empresa_id'              => $empresaId,
                    'affects_rest_day_payment' => true,
                ]
            );
        }

        // 🔔 PARCHE 1: Logging para check_in
        \App\Services\ActivityLogger::log(
            $empresaId,
            $u->id,
            $emp->id,
            'attendance.check_in',
            'attendance_day',
            $day->id,
            [
                'employee_name'   => $emp->full_name ?? $u->name,
                'late_minutes'    => $lateMinutes ?: null,
                'tardiness_count' => $tardCount,
                'date'            => $day->date,
            ],
            $request
        );

        // 🔔 Notificar al empleado si tiene retardos (respeta config)
        if ($tardCount > 0 && $tardinessConfig->notify_employee_on_late) {
            try {
                $penaltyActive = $tardCount >= $tardinessConfig->lates_to_absence;
                SendPushNotification::dispatch(
                    $u->id,
                    $lateMinutes > 0 ? '⏰ Llegada tarde registrada' : '⚡ Alerta de puntualidad',
                    "Ya llevas {$tardCount} retardo(s) este mes" . ($penaltyActive ? ". ¡Tu día de descanso de esta semana no será pagado!" : ". Ten cuidado."),
                    [
                        'type'           => 'attendance.late_warning',
                        'tardiness_count' => (string)$tardCount,
                        'penalty_active' => $penaltyActive ? '1' : '0',
                    ]
                );
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Error notificando retardo al empleado: ' . $e->getMessage());
            }
        }

        // 🔔 Notificar a managers sobre la entrada (respeta config)
        if ($tardinessConfig->notify_manager_on_late || $lateMinutes === 0) {
            try {
                $managerBody = ($emp->full_name ?? $u->name) . ' marcó entrada a las ' . now()->format('H:i');
                if ($lateMinutes > 0) {
                    $managerBody .= " (retardo: {$lateMinutes} min — acumulado mes: {$tardCount} retardo(s))";
                }
                SendPushNotificationToManagers::dispatch(
                    $empresaId,
                    $lateMinutes > 0 ? '⚠️ Entrada tardía' : '📍 Entrada registrada',
                    $managerBody,
                    [
                        'type'            => 'attendance.check_in',
                        'empleado_id'     => $emp->id,
                        'empresa_id'      => $empresaId,
                        'late_minutes'    => (string)$lateMinutes,
                        'tardiness_count' => (string)$tardCount,
                    ]
                );
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Error de notificaciones en entrada: ' . $e->getMessage());
            }
        }

        return response()->json([
            'message'         => $lateMinutes > 0 ? "Entrada registrada con {$lateMinutes} min de retardo" : 'Entrada OK',
            'is_late'         => $lateMinutes > 0,
            'late_minutes'    => $lateMinutes,
            'tardiness_count' => $tardCount,
            'penalty_active'  => $tardCount >= $tardinessConfig->lates_to_absence,
            'day'             => $this->presentDay($day),
        ]);
    }

    // EMPLEADO: iniciar pausa
    public function breakStart(Request $request)
    {
        [$u, $empresaId, $emp] = $this->authEmployee($request);
        $today = now()->toDateString();
        
        if (!$this->validateNetworkAccess($request, Empresa::find($empresaId))) {
            return response()->json(['message' => 'No puedes marcar asistencia fuera de la red de la tienda.', 'code' => 'NETWORK_RESTRICTED'], 403);
        }

        $day = AttendanceDay::where('empresa_id',$empresaId)->where('empleado_id',$emp->id)->where('date',$today)->first();
        if (!$day) return response()->json(['message'=>'No hay entrada registrada hoy'], 409);

        $state = $this->currentState($day);
        if ($state !== 'working') {
            return response()->json(['message'=>'No puedes iniciar pausa ahora', 'state'=>$state], 409);
        }

        $this->addEvent($empresaId, $day->id, 'break_start', $request);

        // 🔔 PARCHE 1: Logging para break_start
        \App\Services\ActivityLogger::log(
            $empresaId,
            $u->id,
            $emp->id,
            'attendance.break_start',
            'attendance_day',
            $day->id,
            ['employee_name' => $emp->full_name ?? $u->name, 'date' => $day->date],
            $request
        );

        return response()->json(['message'=>'Pausa iniciada', 'day'=>$this->presentDay($day->fresh())]);
    }

    // EMPLEADO: terminar pausa
    public function breakEnd(Request $request)
    {
        [$u, $empresaId, $emp] = $this->authEmployee($request);
        $today = now()->toDateString();

        if (!$this->validateNetworkAccess($request, Empresa::find($empresaId))) {
            return response()->json(['message' => 'No puedes marcar asistencia fuera de la red de la tienda.', 'code' => 'NETWORK_RESTRICTED'], 403);
        }

        $day = AttendanceDay::where('empresa_id',$empresaId)->where('empleado_id',$emp->id)->where('date',$today)->first();
        if (!$day) return response()->json(['message'=>'No hay registro hoy'], 409);

        $state = $this->currentState($day);
        if ($state !== 'break') {
            return response()->json(['message'=>'No puedes terminar pausa ahora', 'state'=>$state], 409);
        }

        $this->addEvent($empresaId, $day->id, 'break_end', $request);

        // Detectar exceso de descanso
        $events = AttendanceEvent::where('attendance_day_id', $day->id)
            ->whereIn('type', ['break_start', 'break_end'])
            ->orderBy('occurred_at')
            ->get();

        $breakStart = null;
        $totalBreakSeconds = 0;
        foreach ($events as $e) {
            if ($e->type === 'break_start') $breakStart = $e->occurred_at;
            if ($e->type === 'break_end' && $breakStart) {
                $totalBreakSeconds += max(0, $breakStart->diffInSeconds($e->occurred_at));
                $breakStart = null;
            }
        }

        $empresa = Empresa::find($empresaId);
        $settings = is_array($empresa?->settings) ? $empresa->settings : [];
        $breakDuration = (int)($settings['operativo']['break_duration_minutes'] ?? 10);
        $totalBreakMinutes = (int) round($totalBreakSeconds / 60);

        if ($totalBreakMinutes > $breakDuration) {
            $exceso = $totalBreakMinutes - $breakDuration;
            try {
                SendPushNotificationToManagers::dispatch(
                    $empresaId,
                    '⚠️ Exceso de descanso',
                    ($emp->full_name ?? $u->name) . " excedió el descanso en {$exceso} min (límite: {$breakDuration} min)",
                    [
                        'type'        => 'attendance.break_overtime',
                        'empleado_id' => $emp->id,
                        'exceso_min'  => (string) $exceso,
                    ]
                );
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Error notificación exceso descanso: ' . $e->getMessage());
            }
        }

        // 🔔 PARCHE 1: Logging para break_end
        \App\Services\ActivityLogger::log(
            $empresaId,
            $u->id,
            $emp->id,
            'attendance.break_end',
            'attendance_day',
            $day->id,
            ['employee_name' => $emp->full_name ?? $u->name, 'date' => $day->date],
            $request
        );

        return response()->json(['message'=>'Pausa terminada', 'day'=>$this->presentDay($day->fresh())]);
    }

    // EMPLEADO: salida
    public function checkOut(Request $request)
    {
        [$u, $empresaId, $emp] = $this->authEmployee($request);
        $today = now()->toDateString();
        
        if (!$this->validateNetworkAccess($request, Empresa::find($empresaId))) {
            return response()->json(['message' => 'No puedes marcar asistencia fuera de la red de la tienda.', 'code' => 'NETWORK_RESTRICTED'], 403);
        }

        $day = AttendanceDay::where('empresa_id',$empresaId)->where('empleado_id',$emp->id)->where('date',$today)->first();
        if (!$day) return response()->json(['message'=>'No puedes marcar salida sin entrada'], 409);

        // 🔒 Bloquear si el día ya fue cerrado por un admin/supervisor (status o salida manual)
        if ($day->status === 'closed' || $day->last_check_out_at !== null) {
            return response()->json([
                'message' => 'Tu jornada ya fue cerrada por el administrador. No puedes modificarla.',
                'code'    => 'DAY_ALREADY_CLOSED',
            ], 409);
        }

        $state = $this->currentState($day);
        if ($state !== 'working') {
            return response()->json(['message'=>'No puedes marcar salida ahora', 'state'=>$state], 409);
        }

        $this->addEvent($empresaId, $day->id, 'check_out', $request);

        $now = now();
        $day->last_check_out_at = $now;
        $day->status = 'closed';

        // Salida anticipada: calcular contra required_exit_time
        $requiredExit = AttendanceService::calculateRequiredExitTime($day);
        if ($requiredExit && $now->lessThan($requiredExit)) {
            $day->early_departure_minutes = (int) ceil($requiredExit->diffInMinutes($now));
        } else {
            $day->early_departure_minutes = null;
        }

        $day->save();

        // 🔔 PARCHE 1 FIX: calcular totales reales con computeTotals()
        $totals = AttendanceService::computeDayTotals($day);
        $workedMinutes = $totals['worked_minutes'];
        $breakMinutes  = $totals['break_minutes'];

        \App\Services\ActivityLogger::log(
            $empresaId,
            $u->id,
            $emp->id,
            'attendance.check_out',
            'attendance_day',
            $day->id,
            [
                'employee_name'  => $emp->full_name ?? $u->name,
                'worked_minutes' => $workedMinutes,   // ← ahora tiene valor real calculado
                'break_minutes'  => $breakMinutes,    // ← bonus: también registramos pausas
                'date'           => $day->date,
            ],
            $request
        );

        // 🔔 Notificar a managers sobre la salida
        try {
            SendPushNotificationToManagers::dispatch(
                $empresaId,
                '🚪 Salida registrada',
                ($emp->full_name ?? $u->name) . ' marcó salida a las ' . now()->format('H:i'),
                [
                    'type'        => 'attendance.check_out',
                    'empleado_id' => $emp->id,
                ]
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Error de notificaciones en salida: ' . $e->getMessage());
        }

        return response()->json([
            'message' => 'Salida OK',
            'day' => $this->presentDay($day),
            'early_departure_minutes' => $day->early_departure_minutes,
        ]);
    }

    // EMPLEADO: mis días (rango)
    public function myDays(Request $request)
    {
        [$u, $empresaId, $emp] = $this->authEmployee($request);

        $q = AttendanceDay::where('empresa_id',$empresaId)->where('empleado_id',$emp->id);

        if ($request->filled('from')) $q->whereDate('date','>=',$request->string('from'));
        if ($request->filled('to')) $q->whereDate('date','<=',$request->string('to'));

        $items = $q->orderByDesc('date')->with(['events'])->paginate(31);

        return AttendanceDayResource::collection($items);
    }

    // EMPLEADO: información de retardos del mes actual
    public function myLateInfo(Request $request)
    {
        [$u, $empresaId, $emp] = $this->authEmployee($request);

        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd   = now()->endOfMonth()->toDateString();

        $lateDays = AttendanceDay::where('empresa_id', $empresaId)
            ->where('empleado_id', $emp->id)
            ->whereBetween('date', [$monthStart, $monthEnd])
            ->where('late_minutes', '>', 0)
            ->orderByDesc('date')
            ->get(['date', 'late_minutes']);

        $lateCount = $lateDays->count();

        // Retardo de hoy (si aplica)
        $today = now()->toDateString();
        $todayLate = AttendanceDay::where('empresa_id', $empresaId)
            ->where('empleado_id', $emp->id)
            ->where('date', $today)
            ->value('late_minutes');

        return response()->json([
            'late_count'         => $lateCount,
            'today_late_minutes' => $todayLate,
            'penalty_active'     => $lateCount >= 3,
            'late_days'          => $lateDays->map(fn($d) => [
                'date'         => $d->date?->toDateString(),
                'late_minutes' => $d->late_minutes,
            ]),
            'month'              => now()->format('Y-m'),
        ]);
    }

    // EMPLEADO: estado actual del día
    public function myToday(Request $request)
    {
        [$u, $empresaId, $emp] = $this->authEmployee($request);

        $today = now()->toDateString();

        // 🎉 Verificar si hoy es festivo
        $holiday = Holiday::where('empresa_id', $empresaId)
            ->whereDate('date', $today)
            ->first();

        if ($holiday) {
            $day = AttendanceDay::where('empresa_id', $empresaId)
                ->where('empleado_id', $emp->id)
                ->whereDate('date', $today)
                ->first();

            return response()->json([
                'date'          => $today,
                'is_rest_day'   => false,
                'is_holiday'    => true,
                'holiday_name'  => $holiday->name,
                'state'         => 'holiday',
                'status'        => 'holiday',
                'actions'       => [
                    'check_in'    => false,
                    'break_start' => false,
                    'break_end'   => false,
                    'check_out'   => false,
                ],
                'day'           => $day ? $this->presentDay($day) : [
                    'id'                => null,
                    'empleado_id'       => $emp->id,
                    'date'              => $today,
                    'status'            => 'holiday',
                    'first_check_in_at' => null,
                    'last_check_out_at' => null,
                    'late_minutes'      => 0,
                    'lunch_start_at'    => null,
                    'lunch_end_at'      => null,
                    'lunch_minutes'     => null,
                    'lunch_active'      => false,
                    'lunch_overtime'    => false,
                ],
                'totals'        => null,
            ]);
        }

        $isRest = AttendanceService::isRestDay($empresaId, $emp->id, $today);
        $isNonWorking = AttendanceService::isNonWorkingDay($empresaId, $today);
        $isBlocked = $isRest || $isNonWorking;

        $day = \App\Models\AttendanceDay::where('empresa_id',$empresaId)
            ->where('empleado_id',$emp->id)
            ->where('date',$today)
            ->first();

        $state = $day ? $this->currentState($day) : 'out';

        // 🔒 Si el admin cerró el día manualmente, bloquear todo sin importar los eventos
        $adminClosed = $day && $day->status === 'closed';
        if ($adminClosed) {
            $state = 'closed';
        }

        // Settings operativos
        $empresa = Empresa::find($empresaId);
        $settings = is_array($empresa?->settings) ? $empresa->settings : [];
        $operativo = $settings['operativo'] ?? [];
        $mealDuration = (int)($operativo['meal_duration_minutes'] ?? 30);
        $breakDuration = (int)($operativo['break_duration_minutes'] ?? 10);
        $breakPausesClock = (bool)($operativo['break_pauses_clock'] ?? true);

        // Calcular expected_exit_time (oficial) y required_exit_time (con compensaciones)
        $expectedExitTime = null;
        $requiredExitTime = null;
        if ($day) {
            $expectedExitTime = AttendanceService::calculateOfficialExitTime($day);
            $requiredExitTime = AttendanceService::calculateRequiredExitTime($day);
        }

        // El botón de salida está habilitado si hay entrada y no está cerrado
        $canCheckOut = !$isBlocked && !$adminClosed && $state === 'working';

        // acciones permitidas según estado + descanso / día no laborable
        $actions = [
            'check_in'    => !$isBlocked && !$adminClosed && $state === 'out',
            'break_start' => !$isBlocked && !$adminClosed && $state === 'working',
            'break_end'   => !$isBlocked && !$adminClosed && $state === 'break',
            'check_out'   => $canCheckOut,
        ];

        $totals = null;
        $hasCheckIn = false;
        if ($day) {
            $hasCheckIn = $day->first_check_in_at !== null;
            if ($hasCheckIn) {
                $totals = AttendanceService::computeDayTotals($day);
            }
        }

        [$weekStart, $weekEnd] = AttendanceService::weekRangeForDate($empresaId, $today);
        $restUsedThisWeek = \App\Models\EmployeeCalendarOverride::where('empresa_id', $empresaId)
            ->where('empleado_id', $emp->id)
            ->whereBetween('date', [$weekStart, $weekEnd])
            ->where('type', 'rest')
            ->exists();

        $canMarkRest = ($emp->payment_type === 'daily' && !$hasCheckIn && !$restUsedThisWeek && !$isBlocked && !$adminClosed);

        // Información de hora de llegada y oportunidades
        $employeeCheckInTime = AttendanceService::getEmployeeCheckInTime($empresaId, $emp->id, $today);
        $lateWindowClosesAt = null;
        if ($employeeCheckInTime) {
            $tardinessConfig = \App\Models\TardinessConfig::firstOrCreate(
                ['empresa_id' => $empresaId],
                [
                    'grace_period_minutes'    => 10,
                    'late_threshold_minutes'  => 1,
                    'lates_to_absence'        => 3,
                    'accumulation_period'     => 'month',
                    'penalize_rest_day'       => true,
                    'notify_employee_on_late' => true,
                    'notify_manager_on_late'  => true,
                ]
            );
            $lateWindowClosesAt = \Carbon\Carbon::parse($today . ' ' . $employeeCheckInTime)
                ->addMinutes($tardinessConfig->grace_period_minutes)
                ->addMinutes($tardinessConfig->late_threshold_minutes)
                ->format('H:i');
        }

        $approvedLateRequest = LateArrivalRequest::where('empresa_id', $empresaId)
            ->where('empleado_id', $emp->id)
            ->whereDate('date', $today)
            ->where('status', 'approved')
            ->first();

        $pendingLateRequest = LateArrivalRequest::where('empresa_id', $empresaId)
            ->where('empleado_id', $emp->id)
            ->whereDate('date', $today)
            ->where('status', 'pending')
            ->first();

        return response()->json([
            'date'               => $today,
            'is_rest_day'        => $isRest,
            'is_non_working_day' => $isNonWorking,
            'is_holiday'         => false,
            'state'              => $isBlocked ? ($isRest ? 'rest_day' : 'non_working_day') : $state,
            'status'             => $isBlocked ? ($isRest ? 'rest_day' : 'non_working_day') : $state,
            'admin_closed'       => $adminClosed,
            'is_paid_rest'       => $isRest,
            'can_mark_rest'      => $canMarkRest,
            'rest_used_this_week'=> $restUsedThisWeek,
            'actions'            => $actions,
            'day'                => $day ? $this->presentDay($day) : null,
            'totals'             => $totals,
            'expected_exit_time' => $expectedExitTime?->toISOString(),
            'required_exit_time' => $requiredExitTime?->toISOString(),
            'early_departure_minutes' => $day?->early_departure_minutes,
            'break_pauses_clock' => $breakPausesClock,
            'meal_duration_minutes' => $mealDuration,
            'break_duration_minutes'=> $breakDuration,
            'lunch_reminder_sent'   => (bool)$day?->lunch_reminder_sent,
            'exit_reminder_sent'    => (bool)$day?->exit_reminder_sent,
            'exit_available_sent'   => (bool)$day?->exit_available_sent,
            'employee_check_in_time' => $employeeCheckInTime,
            'late_window_closes_at' => $lateWindowClosesAt,
            'has_approved_late_request' => (bool) $approvedLateRequest,
            'pending_late_request' => $pendingLateRequest ? [
                'id' => $pendingLateRequest->id,
                'status' => $pendingLateRequest->status,
                'motivo' => $pendingLateRequest->motivo,
            ] : null,
        ]);
    }

    public function markRestDay(Request $request)
    {
        [$u, $empresaId, $emp] = $this->authEmployee($request);

        if ($emp->payment_type !== 'daily') {
            return response()->json(['message' => 'Solo los empleados de tipo diario pueden marcar descansos.'], 403);
        }

        $date = $request->input('date', now()->toDateString());

        $hasCheckIn = AttendanceDay::where('empresa_id', $empresaId)
            ->where('empleado_id', $emp->id)
            ->where('date', $date)
            ->whereNotNull('first_check_in_at')
            ->exists();

        if ($hasCheckIn) {
            return response()->json(['message' => 'No puedes marcar descanso si ya marcaste entrada este día.'], 409);
        }

        $override = EmployeeCalendarOverride::where('empresa_id', $empresaId)
            ->where('empleado_id', $emp->id)
            ->where('date', $date)
            ->first();

        if ($override && $override->type === 'rest') {
            return response()->json(['message' => 'Ya está registrado como descanso.'], 409);
        }

        [$weekStart, $weekEnd] = AttendanceService::weekRangeForDate($empresaId, $date);
        
        $hasRestThisWeek = EmployeeCalendarOverride::where('empresa_id', $empresaId)
            ->where('empleado_id', $emp->id)
            ->whereBetween('date', [$weekStart, $weekEnd])
            ->where('type', 'rest')
            ->exists();

        if ($hasRestThisWeek) {
            return response()->json([
                'message' => 'Ya tienes un día de descanso registrado esta semana.',
                'code' => 'REST_ALREADY_USED'
            ], 409);
        }

        EmployeeCalendarOverride::updateOrCreate(
            ['empresa_id' => $empresaId, 'empleado_id' => $emp->id, 'date' => $date],
            ['type' => 'rest', 'is_paid' => true]
        );

        return response()->json([
            'message' => 'Día de descanso registrado correctamente.',
            'date' => $date,
            'is_paid' => true
        ], 200);
    }

    public function cancelRestDay(Request $request, string $date)
    {
        [$u, $empresaId, $emp] = $this->authEmployee($request);

        $override = EmployeeCalendarOverride::where('empresa_id', $empresaId)
            ->where('empleado_id', $emp->id)
            ->where('date', $date)
            ->where('type', 'rest')
            ->first();

        if ($override) {
            $hasApprovedPayroll = \App\Models\PayrollEntry::where('empleado_id', $emp->id)
                ->whereHas('period', function($q) use ($date) {
                    $q->where('status', 'approved')->where('start_date', '<=', $date)->where('end_date', '>=', $date);
                })->exists();

            if ($hasApprovedPayroll) {
                return response()->json(['message' => 'No se puede cancelar porque ya existe nómina aprobada para esta fecha.'], 409);
            }

            $override->delete();
        }

        return response()->json(['message' => 'Descanso cancelado correctamente.']);
    }

    // POST /asistencia/dia-descanso (admin marca para cualquier empleado)
    public function marcarDiaDescansoAdmin(Request $request)
    {
        Gate::authorize('admin');
        $u = $request->user();

        $data = $request->validate([
            'empleado_id' => ['required', 'uuid'],
            'fecha'       => ['required', 'date_format:Y-m-d'],
            'motivo'      => ['nullable', 'string', 'max:300'],
        ]);

        // Verificar que el empleado pertenece a la empresa
        $emp = Empleado::where('empresa_id', $u->empresa_id)
            ->where('id', $data['empleado_id'])
            ->firstOrFail();

        // Verificar que no haya ya una asistencia registrada ese día
        $existing = AttendanceDay::where('empresa_id', $u->empresa_id)
            ->where('empleado_id', $emp->id)
            ->where('date', $data['fecha'])
            ->first();

        if ($existing && in_array($existing->status, ['present', 'late', 'open', 'working', 'closed'])) {
            return response()->json([
                'message' => 'El empleado ya tiene asistencia o turno este día. Usa "Ajustar asistencia" si necesitas corregirla.'
            ], 409);
        }

        // Crear o actualizar el día de descanso (attendance_day)
        $day = AttendanceDay::updateOrCreate(
            [
                'empresa_id'  => $u->empresa_id,
                'empleado_id' => $emp->id,
                'date'        => $data['fecha'],
            ],
            [
                'status'            => 'day_off',
                'first_check_in_at' => null,
                'last_check_out_at' => null,
            ]
        );

        // Crear o actualizar también en employee_calendar_overrides para reportes o reglas adicionales
        EmployeeCalendarOverride::updateOrCreate(
            ['empresa_id' => $u->empresa_id, 'empleado_id' => $emp->id, 'date' => $data['fecha']],
            ['type' => 'rest', 'is_paid' => true]
        );

        // Log de auditoría
        \App\Services\ActivityLogger::log(
            $u->empresa_id,
            $u->id,
            null,
            'attendance.day_off_added',
            'attendance_day',
            $day->id,
            [
                'empleado_name' => $emp->full_name,
                'fecha'         => $data['fecha'],
                'motivo'        => $data['motivo'] ?? 'Sin motivo especificado',
                'added_by'      => $u->name,
            ],
            $request
        );

        return response()->json([
            'message'    => "Día de descanso registrado para {$emp->full_name}",
            'attendance' => $day,
        ]);
    }

    // DELETE /asistencia/dia-descanso (admin quita día de descanso)
    public function quitarDiaDescansoAdmin(Request $request)
    {
        Gate::authorize('admin');
        $u = $request->user();

        $data = $request->validate([
            'empleado_id' => ['required', 'uuid'],
            'fecha'       => ['required', 'date_format:Y-m-d'],
        ]);

        $emp = Empleado::where('empresa_id', $u->empresa_id)
            ->where('id', $data['empleado_id'])
            ->firstOrFail();

        $day = AttendanceDay::where('empresa_id', $u->empresa_id)
            ->where('empleado_id', $emp->id)
            ->where('date', $data['fecha'])
            ->where('status', 'day_off')
            ->first();

        if ($day) {
            $day->delete();
        }

        // Quitar también del employee_calendar_overrides
        $override = EmployeeCalendarOverride::where('empresa_id', $u->empresa_id)
            ->where('empleado_id', $emp->id)
            ->where('date', $data['fecha'])
            ->where('type', 'rest')
            ->first();
        if ($override) {
            $override->delete();
        }

        return response()->json([
            'message' => "Día de descanso eliminado para {$emp->full_name} el {$data['fecha']}",
        ]);
    }

    // ADMIN/SUPERVISOR: por fecha (resumen)
    public function byDate(Request $request)
    {
        Gate::authorize('supervisor');
        $u = $request->user();
        $empresaId = $u->empresa_id;

        $data = $request->validate([
            'date' => ['required','date']
        ]);
        $date = $data['date'];

        $days = AttendanceDay::where('empresa_id',$empresaId)->where('date',$date)->with(['events'])->get();

        return response()->json([
            'date' => $date,
            'items' => AttendanceDayResource::collection($days),
        ]);
    }

    // ADMIN/SUPERVISOR: resumen semanal por empleado (payable minutes)
    public function weeklySummary(Request $request)
    {
        Gate::authorize('supervisor');
        $u = $request->user();
        $empresaId = $u->empresa_id;

        $data = $request->validate([
            'empleado_id' => ['required','uuid'],
            'date' => ['required','date'], // cualquier fecha dentro de la semana
        ]);

        $emp = Empleado::where('empresa_id',$empresaId)->where('id',$data['empleado_id'])->first();
        if (!$emp) return response()->json(['message'=>'Empleado no encontrado'], 404);

        [$weekStart, $weekEnd] = AttendanceService::weekRangeForDate($empresaId, $data['date']);

        $days = AttendanceDay::where('empresa_id',$empresaId)
            ->where('empleado_id',$emp->id)
            ->whereBetween('date', [$weekStart, $weekEnd])
            ->with(['events'])
            ->get();

        $worked = 0;
        $breaks = 0;

        foreach ($days as $d) {
            $totals = AttendanceService::computeDayTotals($d);
            $worked += $totals['worked_minutes'];
            $breaks += $totals['break_minutes'];
        }

        // descanso pagado (overrides o descanso semanal)
        $paidRest = AttendanceService::computePaidRestMinutes($empresaId, $emp->id, $weekStart, $weekEnd, (float)$emp->daily_hours);

        return response()->json([
            'week' => ['from'=>$weekStart, 'to'=>$weekEnd],
            'empleado' => [
                'id'=>$emp->id,
                'full_name'=>$emp->full_name,
                'daily_hours'=>(float)$emp->daily_hours,
                'rest_weekday'=>$emp->rest_weekday,
            ],
            'days' => AttendanceDayResource::collection($days),
            'totals' => [
                'worked_minutes'=>$worked,
                'break_minutes'=>$breaks,
                'paid_rest_minutes'=>$paidRest,
                'payable_minutes'=>$worked + $paidRest
            ]
        ]);
    }

    // ─────────────────────────────────────────────
    // NUEVOS MÉTODOS: ajustar, iniciarComida, terminarComida
    // ─────────────────────────────────────────────

    /**
     * PATCH /asistencia/ajustar/{empleadoId}/{fecha}
     * Admin/Supervisor puede corregir la hora de entrada o salida de un dia.
     */
    public function ajustar(Request $request, string $empleadoId, string $fecha)
    {
        Gate::authorize('supervisor');
        $u = $request->user();

        $data = $request->validate([
            'first_check_in_at'  => ['nullable', 'date_format:H:i'],
            'last_check_out_at'  => ['nullable', 'date_format:H:i'],
            'motivo'             => ['required', 'string', 'max:300'],
        ]);

        $emp = Empleado::where('empresa_id', $u->empresa_id)
            ->where('id', $empleadoId)
            ->firstOrFail();

        $day = AttendanceDay::firstOrCreate(
            [
                'empresa_id'  => $u->empresa_id,
                'empleado_id' => $emp->id,
                'date'        => $fecha,
            ],
            ['status' => 'present']
        );

        if (array_key_exists('first_check_in_at', $data) && $data['first_check_in_at']) {
            $day->first_check_in_at = \Carbon\Carbon::parse($fecha . ' ' . $data['first_check_in_at']);
        }

        if (array_key_exists('last_check_out_at', $data) && $data['last_check_out_at']) {
            $day->last_check_out_at = \Carbon\Carbon::parse($fecha . ' ' . $data['last_check_out_at']);
        }

        // 🔒 Si tiene salida ajustada → cerrar el día para que el empleado no pueda seguir marcando
        if ($day->last_check_out_at) {
            $day->status = 'closed';
        } elseif ($day->first_check_in_at) {
            $day->status = 'open';
        }

        $day->save();

        \App\Services\ActivityLogger::log(
            $u->empresa_id,
            $u->id,
            null,
            'attendance.adjusted',
            'attendance_day',
            $day->id,
            [
                'empleado_name'      => $emp->full_name,
                'fecha'              => $fecha,
                'motivo'             => $data['motivo'],
                'adjusted_check_in'  => $data['first_check_in_at'] ?? null,
                'adjusted_check_out' => $data['last_check_out_at'] ?? null,
                'adjusted_by'        => $u->name,
            ],
            $request
        );

        return response()->json([
            'message' => 'Asistencia ajustada correctamente',
            'day'     => $this->presentDay($day),
        ]);
    }

    /**
     * DELETE /asistencia/eliminar/{empleadoId}/{fecha}
     * Admin/Supervisor puede eliminar un día de asistencia.
     */
    public function eliminarDia(Request $request, string $empleadoId, string $fecha)
    {
        Gate::authorize('supervisor');
        $u = $request->user();

        $emp = Empleado::where('empresa_id', $u->empresa_id)
            ->where('id', $empleadoId)
            ->firstOrFail();

        $day = AttendanceDay::where('empresa_id', $u->empresa_id)
            ->where('empleado_id', $emp->id)
            ->where('date', $fecha)
            ->first();

        if (!$day) {
            return response()->json(['message' => 'No se encontró registro de asistencia para este día'], 404);
        }

        \App\Models\AttendanceEvent::where('attendance_day_id', $day->id)->delete();
        $day->delete();

        \App\Services\ActivityLogger::log(
            $u->empresa_id,
            $u->id,
            null,
            'attendance.deleted',
            'empleado', 
            $emp->id,
            [
                'empleado_name' => $emp->full_name,
                'fecha'         => $fecha,
                'deleted_by'    => $u->name,
            ],
            $request
        );

        return response()->json(['message' => 'Asistencia eliminada correctamente']);
    }

    /**
     * POST /asistencia/comida/iniciar
     * El empleado inicia su tiempo de comida.
     */
    public function iniciarComida(Request $request)
    {
        [$u, $empresaId, $emp] = $this->authEmployee($request);

        $hoy = now()->toDateString();
        $day = AttendanceDay::where('empresa_id', $empresaId)
            ->where('empleado_id', $emp->id)
            ->where('date', $hoy)
            ->first();

        if (!$day || !$day->first_check_in_at) {
            return response()->json(['message' => 'Debes marcar entrada primero'], 422);
        }

        if ($day->lunch_start_at) {
            return response()->json(['message' => 'Ya iniciaste tu tiempo de comida'], 409);
        }

        $day->lunch_start_at = now();
        $day->save();

        // Leer duración configurada
        $empresa = Empresa::find($empresaId);
        $settings = is_array($empresa?->settings) ? $empresa->settings : [];
        $mealDuration = (int)($settings['operativo']['meal_duration_minutes'] ?? 30);

        // 🔔 Notificar al supervisor
        try {
            SendPushNotificationToManagers::dispatch(
                $empresaId,
                '🍽️ Inicio de comida',
                ($emp->full_name ?? $u->name) . ' inició su tiempo de comida',
                ['type' => 'attendance.lunch_start', 'empleado_id' => $emp->id]
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Error de notificaciones en inicio de comida: ' . $e->getMessage());
        }

        return response()->json([
            'message'        => 'Tiempo de comida iniciado',
            'lunch_start_at' => $day->lunch_start_at->toISOString(),
            'lunch_limit_at' => $day->lunch_start_at->copy()->addMinutes($mealDuration)->toISOString(),
        ]);
    }

    /**
     * POST /asistencia/comida/terminar
     * El empleado termina su tiempo de comida.
     */
    public function terminarComida(Request $request)
    {
        [$u, $empresaId, $emp] = $this->authEmployee($request);

        $hoy = now()->toDateString();
        $day = AttendanceDay::where('empresa_id', $empresaId)
            ->where('empleado_id', $emp->id)
            ->where('date', $hoy)
            ->first();

        if (!$day?->lunch_start_at) {
            return response()->json(['message' => 'No has iniciado tu tiempo de comida'], 422);
        }

        if ($day->lunch_end_at) {
            return response()->json(['message' => 'Tu tiempo de comida ya terminó'], 409);
        }

        $day->lunch_end_at = now();
        $day->save();

        // Leer duración configurada
        $empresa = Empresa::find($empresaId);
        $settings = is_array($empresa?->settings) ? $empresa->settings : [];
        $mealDuration = (int)($settings['operativo']['meal_duration_minutes'] ?? 30);

        $minutos = (int) round($day->lunch_start_at->diffInMinutes($day->lunch_end_at));
        $excedio = $minutos > $mealDuration;

        if ($excedio) {
            $day->meal_overtime_minutes = max(0, $minutos - $mealDuration);
            $day->save();

            // 🔔 Notificar si se pasó del tiempo
            try {
                SendPushNotificationToManagers::dispatch(
                    $empresaId,
                    '⚠️ Tiempo de comida excedido',
                    ($emp->full_name ?? $u->name) . " tardó {$minutos} min en comida (límite: {$mealDuration} min)",
                    [
                        'type'        => 'attendance.lunch_overtime',
                        'empleado_id' => $emp->id,
                        'minutos'     => (string) $minutos,
                    ]
                );
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Error de notificaciones en termino de comida: ' . $e->getMessage());
            }
        }

        return response()->json([
            'message'        => 'Tiempo de comida terminado',
            'lunch_start_at' => $day->lunch_start_at->toISOString(),
            'lunch_end_at'   => $day->lunch_end_at->toISOString(),
            'minutos'        => $minutos,
            'excedio'        => $excedio,
        ]);
    }

    // ----------------- helpers -----------------

    private function authEmployee(Request $request): array
    {
        $u = $request->user();
        if (!$u) {
            abort(response()->json(['message'=>'No autenticado'], 401));
        }

        $empresaId = $u->empresa_id;

        $emp = Empleado::where('empresa_id',$empresaId)->where('user_id',$u->id)->first();
        if (!$emp) abort(response()->json(['message'=>'Empleado no vinculado'], 404));

        return [$u, $empresaId, $emp];
    }

    private function validateNetworkAccess(Request $request, Empresa $empresa): bool
    {
        $allowedIp = $empresa->allowed_ip;
        if (!$allowedIp) return true;

        $clientIp = $request->ip();

        if (str_contains($allowedIp, '/')) {
            return \Symfony\Component\HttpFoundation\IpUtils::checkIp($clientIp, $allowedIp);
        }

        return $clientIp === $allowedIp;
    }

    private function addEvent(string $empresaId, string $dayId, string $type, Request $request): void
    {
        AttendanceEvent::create([
            'empresa_id' => $empresaId,
            'attendance_day_id' => $dayId,
            'type' => $type,
            'occurred_at' => now(),
            'meta' => [
                'ip' => $request->ip(),
                'ua' => substr((string)$request->userAgent(), 0, 250),
            ]
        ]);
    }

    // out|working|break|closed
    private function currentState(AttendanceDay $day): string
    {
        // Cerrado si el status es 'closed' O si el admin ya registró una salida manual
        if ($day->status === 'closed' || $day->last_check_out_at !== null) return 'closed';

        $events = AttendanceEvent::where('attendance_day_id',$day->id)->orderBy('occurred_at')->get();
        if ($events->isEmpty()) return 'out';

        $last = $events->last()->type;

        return match ($last) {
            'check_in' => 'working',
            'break_start' => 'break',
            'break_end' => 'working',
            'check_out' => 'closed',
            default => 'out',
        };
    }

    // ✅ FIX: retorna [worked_minutes, break_minutes] con soporte para turnos abiertos y ajustes manuales
    private function computeTotals(string $empresaId, string $dayId): array
    {
        $day = AttendanceDay::find($dayId);
        $events = AttendanceEvent::where('empresa_id',$empresaId)
            ->where('attendance_day_id',$dayId)
            ->orderBy('occurred_at')
            ->get();

        $checkIn    = null;
        $checkOut   = null;
        $breakStart = null;
        $breakSeconds = 0;

        foreach ($events as $e) {
            if ($e->type === 'check_in')  $checkIn  = $checkIn ?? $e->occurred_at;
            if ($e->type === 'check_out') $checkOut = $e->occurred_at;

            if ($e->type === 'break_start') $breakStart = $e->occurred_at;
            if ($e->type === 'break_end' && $breakStart) {
                // ✅ FIX: breakStart (más antiguo) → occurred_at (más nuevo)
                $breakSeconds += max(0, $breakStart->diffInSeconds($e->occurred_at));
                $breakStart = null;
            }
        }

        // Si el admin hizo ajustes manuales (AttendanceDay tiene los datos correctos), 
        // estos valores tienen prioridad sobre los AttendanceEvent originales.
        if ($day) {
            if ($day->first_check_in_at) $checkIn = $day->first_check_in_at;
            if ($day->last_check_out_at) $checkOut = $day->last_check_out_at;
        }

        if (!$checkIn) {
            return [0, 0];
        }

        $effectiveCheckOut = $checkOut ?? now();

        // ✅ FIX: si hay pausa abierta, contar hasta el final del turno o hasta ahora
        if ($breakStart) {
            $breakEndLimit = min(now(), $effectiveCheckOut);
            if ($breakStart < $breakEndLimit) {
                $breakSeconds += max(0, $breakStart->diffInSeconds($breakEndLimit));
            }
        }

        // ✅ FIX: checkIn (más antiguo) → effectiveCheckOut (más nuevo)
        $totalSeconds  = max(0, $checkIn->diffInSeconds($effectiveCheckOut));
        $workedSeconds = max(0, $totalSeconds - $breakSeconds);

        return [
            (int) round($workedSeconds / 60),
            (int) round($breakSeconds  / 60),
        ];
    }

    private function presentDay(AttendanceDay $d): array
    {
        // Calcular minutos de comida (completada o en curso)
        $lunchMinutes = null;
        if ($d->lunch_start_at && $d->lunch_end_at) {
            $lunchMinutes = (int) round($d->lunch_start_at->diffInMinutes($d->lunch_end_at));
        } elseif ($d->lunch_start_at) {
            $lunchMinutes = (int) round($d->lunch_start_at->diffInMinutes(now()));
        }

        // Si hay retardo registrado, reportar 'late'.
        // Si no hay late_minutes en DB, intentar calcularlo al vuelo usando la config de la empresa.
        $effectiveStatus = $d->status;
        $lateMins = (int)($d->late_minutes ?? 0);

        if ($lateMins === 0 && $d->first_check_in_at) {
            $daySchedule = AttendanceService::getDaySchedule($d->empresa_id, $d->date->toDateString());
            $checkInTimeStr = $daySchedule['check_in_time'] ?? null;

            if ($checkInTimeStr) {
                $lateMins = AttendanceService::calculateLateMinutes($d->first_check_in_at, $checkInTimeStr);
            }
        }

        if ($lateMins > 0 && in_array($d->status, ['open', 'closed', 'present'])) {
            $effectiveStatus = 'late';
        }

        // Leer duración configurada para overtime de comida
        $empresa = Empresa::find($d->empresa_id);
        $settings = is_array($empresa?->settings) ? $empresa->settings : [];
        $mealDuration = (int)($settings['operativo']['meal_duration_minutes'] ?? 30);

        return [
            'id'                => $d->id,
            'empleado_id'       => $d->empleado_id,
            'date'              => $d->date?->toDateString(),
            'status'            => $effectiveStatus,
            'first_check_in_at' => $d->first_check_in_at?->toISOString(),
            'last_check_out_at' => $d->last_check_out_at?->toISOString(),
            // Retardo
            'late_minutes'      => $lateMins,
            // Salida anticipada y exceso comida
            'early_departure_minutes' => $d->early_departure_minutes,
            'meal_overtime_minutes'   => $d->meal_overtime_minutes,
            'required_exit_time' => \App\Services\AttendanceService::calculateRequiredExitTime($d)?->toISOString(),
            // Campos de comida
            'lunch_start_at'    => $d->lunch_start_at?->toISOString(),
            'lunch_end_at'      => $d->lunch_end_at?->toISOString(),
            'lunch_minutes'     => $lunchMinutes,
            'lunch_active'      => $d->lunch_start_at && !$d->lunch_end_at,
            'lunch_overtime'    => $d->lunch_start_at && !$d->lunch_end_at
                ? now()->diffInMinutes($d->lunch_start_at) > $mealDuration
                : false,
        ];
    }

}
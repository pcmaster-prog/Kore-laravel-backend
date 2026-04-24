<?php
// AttendanceControllerV2: manejo de asistencia (check-in, check-out, pausas) con lógica de estados, eventos y cálculo de totales.
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\AttendanceDay;
use App\Models\AttendanceEvent;
use App\Models\Empleado;
use App\Models\EmployeeCalendarOverride;
use App\Models\Empresa;
use App\Services\NotificationService;

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
        $settings = is_array($empresa->settings) ? $empresa->settings : [];
        $operativo = $settings['operativo'] ?? null;
        if ($operativo && isset($operativo['check_in_time'])) {
            $checkInTimeStr = $operativo['check_in_time']; // E.g., "08:20"

            $todayCheckIn  = \Carbon\Carbon::parse($today . ' ' . $checkInTimeStr);
            $todayEarliest = $todayCheckIn->copy()->subMinutes(15);

            $now = now(); // Hora actual del servidor (timezone de config/app.php)

            // ❌ Demasiado temprano
            if ($now->lessThan($todayEarliest)) {
                return response()->json([
                    'message' => "Entrada bloqueada: Aún es muy temprano. El acceso para las " . $todayCheckIn->format('H:i') . " se permite desde las " . $todayEarliest->format('H:i') . ". Tu hora actual es: " . $now->format('H:i'),
                    'code'                => 'CHECK_IN_TOO_EARLY',
                    'earliest_allowed'    => $todayEarliest->toTimeString(),
                    'current_server_time' => $now->toTimeString(),
                ], 409);
            }

            // ❌ Demasiado tarde (después de la hora de salida configurada)
            if (isset($operativo['check_out_time'])) {
                $checkOutTimeStr = $operativo['check_out_time']; // E.g., "17:10"
                $todayCheckOut   = \Carbon\Carbon::parse($today . ' ' . $checkOutTimeStr);

                if ($now->greaterThan($todayCheckOut)) {
                    return response()->json([
                        'message' => "Entrada bloqueada: El turno ya terminó. La hora de salida era las " . $todayCheckOut->format('H:i') . ". Tu hora actual es: " . $now->format('H:i'),
                        'code'                => 'CHECK_IN_TOO_LATE',
                        'latest_allowed'      => $todayCheckOut->toTimeString(),
                        'current_server_time' => $now->toTimeString(),
                    ], 409);
                }
            }
        }

        if (!$this->validateNetworkAccess($request, $empresa)) {
            return response()->json([
                'message' => 'No puedes marcar asistencia fuera de la red de la tienda.',
                'code' => 'NETWORK_RESTRICTED'
            ], 403);
        }

        if ($this->isRestDay($empresaId, $emp->id, $today)) {
            return response()->json(['message'=>'Hoy es día de descanso'], 409);
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

        $freshSettings = is_array($empresa->settings) ? $empresa->settings : [];
        $freshOperativo = $freshSettings['operativo'] ?? null;

        if ($freshOperativo && isset($freshOperativo['check_in_time'])) {
            $scheduledTime = \Carbon\Carbon::parse($today . ' ' . $freshOperativo['check_in_time']);
            $graceLimit    = $scheduledTime->copy()->addMinutes($tardinessConfig->grace_period_minutes);
            $lateThreshold = $graceLimit->copy()->addMinutes($tardinessConfig->late_threshold_minutes);
            $nowTs         = now();

            if ($nowTs->greaterThan($lateThreshold)) {
                $lateMinutes = (int) ceil($nowTs->diffInMinutes($scheduledTime));
            }
        }

        if ($lateMinutes > 0) {
            $day->late_minutes = $lateMinutes;
            $day->status = 'late'; // ← marcar retardo en DB para que el admin lo vea
        }
        $day->save();

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
                app(NotificationService::class)->sendToUser(
                    userId: $u->id,
                    title: $lateMinutes > 0 ? '⏰ Llegada tarde registrada' : '⚡ Alerta de puntualidad',
                    body: "Ya llevas {$tardCount} retardo(s) este mes" . ($penaltyActive ? ". ¡Tu día de descanso de esta semana no será pagado!" : ". Ten cuidado."),
                    data: [
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
                app(NotificationService::class)->sendToManagers(
                    empresaId: $empresaId,
                    title: $lateMinutes > 0 ? '⚠️ Entrada tardía' : '📍 Entrada registrada',
                    body: $managerBody,
                    data: [
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

        $day->last_check_out_at = now();
        $day->status = 'closed';
        $day->save();

        // 🔔 PARCHE 1 FIX: calcular totales reales con computeTotals()
        [$workedMinutes, $breakMinutes] = $this->computeTotals($empresaId, $day->id);

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
            app(NotificationService::class)->sendToManagers(
                empresaId: $empresaId,
                title: '🚪 Salida registrada',
                body: ($emp->full_name ?? $u->name) . ' marcó salida a las ' . now()->format('H:i'),
                data: [
                    'type'        => 'attendance.check_out',
                    'empleado_id' => $emp->id,
                ]
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Error de notificaciones en salida: ' . $e->getMessage());
        }

        return response()->json(['message'=>'Salida OK', 'day'=>$this->presentDay($day)]);
    }

    // EMPLEADO: mis días (rango)
    public function myDays(Request $request)
    {
        [$u, $empresaId, $emp] = $this->authEmployee($request);

        $q = AttendanceDay::where('empresa_id',$empresaId)->where('empleado_id',$emp->id);

        if ($request->filled('from')) $q->whereDate('date','>=',$request->string('from'));
        if ($request->filled('to')) $q->whereDate('date','<=',$request->string('to'));

        $items = $q->orderByDesc('date')->paginate(31);

        // incluir cálculo de totales por día
        $items->getCollection()->transform(function ($d) use ($empresaId) {
            [$worked, $breaks] = $this->computeTotals($empresaId, $d->id);
            $arr = $this->presentDay($d);
            $arr['totals'] = ['worked_minutes'=>$worked, 'break_minutes'=>$breaks];
            return $arr;
        });

        return response()->json($items);
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

        $isRest = $this->isRestDay($empresaId, $emp->id, $today);

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

        // acciones permitidas según estado + descanso
        $actions = [
            'check_in'    => !$isRest && !$adminClosed && $state === 'out',
            'break_start' => !$isRest && !$adminClosed && $state === 'working',
            'break_end'   => !$isRest && !$adminClosed && $state === 'break',
            'check_out'   => !$isRest && !$adminClosed && $state === 'working',
        ];

        $totals = null;
        $hasCheckIn = false;
        if ($day) {
            $hasCheckIn = $day->first_check_in_at !== null;
        }

        [$weekStart, $weekEnd] = $this->weekRangeForDate($empresaId, $today);
        $restUsedThisWeek = \App\Models\EmployeeCalendarOverride::where('empresa_id', $empresaId)
            ->where('empleado_id', $emp->id)
            ->whereBetween('date', [$weekStart, $weekEnd])
            ->where('type', 'rest')
            ->exists();

        $canMarkRest = ($emp->payment_type === 'daily' && !$hasCheckIn && !$restUsedThisWeek && !$isRest && !$adminClosed);

        return response()->json([
            'date'               => $today,
            'is_rest_day'        => $isRest,
            'state'              => $isRest ? 'rest_day' : $state,
            'status'             => $isRest ? 'rest_day' : $state,
            'admin_closed'       => $adminClosed,
            'is_paid_rest'       => $isRest,
            'can_mark_rest'      => $canMarkRest,
            'rest_used_this_week'=> $restUsedThisWeek,
            'actions'            => $actions,
            'day'                => $day ? $this->presentDay($day) : null,
            'totals'             => $totals,
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

        [$weekStart, $weekEnd] = $this->weekRangeForDate($empresaId, $date);
        
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
        $u = $request->user();
        if ($u->role !== 'admin') {
            return response()->json(['message' => 'Solo el administrador puede marcar días de descanso'], 403);
        }

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
        $u = $request->user();
        if ($u->role !== 'admin') {
            return response()->json(['message' => 'Solo el administrador puede modificar días de descanso'], 403);
        }

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
        $u = $request->user();
        if (!in_array($u->role, ['admin','supervisor'])) {
            return response()->json(['message'=>'No autorizado'], 403);
        }

        $empresaId = $u->empresa_id;

        $data = $request->validate([
            'date' => ['required','date']
        ]);
        $date = $data['date'];

        $days = AttendanceDay::where('empresa_id',$empresaId)->where('date',$date)->get();

        $items = $days->map(function ($d) use ($empresaId) {
            [$worked, $breaks] = $this->computeTotals($empresaId, $d->id);
            $arr = $this->presentDay($d);
            $arr['totals'] = ['worked_minutes'=>$worked, 'break_minutes'=>$breaks];
            return $arr;
        });

        return response()->json(['date'=>$date, 'items'=>$items]);
    }

    // ADMIN/SUPERVISOR: resumen semanal por empleado (payable minutes)
    public function weeklySummary(Request $request)
    {
        $u = $request->user();
        if (!in_array($u->role, ['admin','supervisor'])) {
            return response()->json(['message'=>'No autorizado'], 403);
        }

        $empresaId = $u->empresa_id;

        $data = $request->validate([
            'empleado_id' => ['required','uuid'],
            'date' => ['required','date'], // cualquier fecha dentro de la semana
        ]);

        $emp = Empleado::where('empresa_id',$empresaId)->where('id',$data['empleado_id'])->first();
        if (!$emp) return response()->json(['message'=>'Empleado no encontrado'], 404);

        [$weekStart, $weekEnd] = $this->weekRangeForDate($empresaId, $data['date']);

        $days = AttendanceDay::where('empresa_id',$empresaId)
            ->where('empleado_id',$emp->id)
            ->whereBetween('date', [$weekStart, $weekEnd])
            ->get();

        $worked = 0;
        $breaks = 0;

        foreach ($days as $d) {
            [$w, $b] = $this->computeTotals($empresaId, $d->id);
            $worked += $w;
            $breaks += $b;
        }

        // descanso pagado (overrides o descanso semanal)
        $paidRest = $this->computePaidRestMinutes($empresaId, $emp->id, $weekStart, $weekEnd, (float)$emp->daily_hours);

        return response()->json([
            'week' => ['from'=>$weekStart, 'to'=>$weekEnd],
            'empleado' => [
                'id'=>$emp->id,
                'full_name'=>$emp->full_name,
                'daily_hours'=>(float)$emp->daily_hours,
                'rest_weekday'=>$emp->rest_weekday,
            ],
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
        $u = $request->user();
        if (!in_array($u->role, ['admin', 'supervisor'])) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

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
        $u = $request->user();
        if (!in_array($u->role, ['admin', 'supervisor'])) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

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
     * El empleado inicia su tiempo de comida (máx. 30 min).
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

        // 🔔 Notificar al supervisor
        try {
            app(NotificationService::class)->sendToManagers(
                empresaId: $empresaId,
                title: '🍽️ Inicio de comida',
                body: ($emp->full_name ?? $u->name) . ' inició su tiempo de comida',
                data: ['type' => 'attendance.lunch_start', 'empleado_id' => $emp->id]
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Error de notificaciones en inicio de comida: ' . $e->getMessage());
        }

        return response()->json([
            'message'        => 'Tiempo de comida iniciado',
            'lunch_start_at' => $day->lunch_start_at->toISOString(),
            'lunch_limit_at' => $day->lunch_start_at->copy()->addMinutes(30)->toISOString(),
        ]);
    }

    /**
     * POST /asistencia/comida/terminar
     * El empleado termina su tiempo de comida. Notifica si excedió 30 min.
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

        $minutos = (int) round($day->lunch_start_at->diffInMinutes($day->lunch_end_at));
        $excedio = $minutos > 30;

        // 🔔 Notificar si se pasó del tiempo
        if ($excedio) {
            try {
                app(NotificationService::class)->sendToManagers(
                    empresaId: $empresaId,
                    title: '⚠️ Tiempo de comida excedido',
                    body: ($emp->full_name ?? $u->name) . " tardó {$minutos} min en comida (límite: 30 min)",
                    data: [
                        'type'        => 'attendance.lunch_overtime',
                        'empleado_id' => $emp->id,
                        'minutos'     => $minutos,
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

        // Si hay retardo registrado, reportar 'late' sin importar si el status en DB
        // es 'open' o 'closed' (para cubrir registros históricos que no lo tenían).
        $effectiveStatus = $d->status;
        if (($d->late_minutes ?? 0) > 0 && in_array($d->status, ['open', 'closed', 'present'])) {
            $effectiveStatus = 'late';
        }

        return [
            'id'                => $d->id,
            'empleado_id'       => $d->empleado_id,
            'date'              => $d->date?->toDateString(),
            'status'            => $effectiveStatus,
            'first_check_in_at' => $d->first_check_in_at?->toISOString(),
            'last_check_out_at' => $d->last_check_out_at?->toISOString(),
            // Retardo
            'late_minutes'      => $d->late_minutes,
            // Campos de comida
            'lunch_start_at'    => $d->lunch_start_at?->toISOString(),
            'lunch_end_at'      => $d->lunch_end_at?->toISOString(),
            'lunch_minutes'     => $lunchMinutes,
            'lunch_active'      => $d->lunch_start_at && !$d->lunch_end_at,
            'lunch_overtime'    => $d->lunch_start_at && !$d->lunch_end_at
                ? now()->diffInMinutes($d->lunch_start_at) > 30
                : false,
        ];
    }

    // Determinar si una fecha es descanso para ese empleado
    private function isRestDay(string $empresaId, string $empleadoId, string $date): bool
    {
        // override por fecha manda
        $ov = EmployeeCalendarOverride::where('empresa_id',$empresaId)
            ->where('empleado_id',$empleadoId)
            ->where('date',$date)
            ->first();

        if ($ov) return $ov->type === 'rest';

        // si no override, usamos rest_weekday del empleado (si existe)
        $emp = Empleado::where('empresa_id',$empresaId)->where('id',$empleadoId)->first();
        if (!$emp || $emp->rest_weekday === null) return false;

        $weekStart = $this->weekStartIndex($empresaId);

        // "weekday relativo a week_start"
        // convertimos el weekday real (0=domingo) a relativo
        $realWeekday = (int) now()->parse($date)->dayOfWeek; // 0-6, domingo=0
        $relative = ($realWeekday - $weekStart + 7) % 7;

        return $relative === (int)$emp->rest_weekday;
    }

    private function weekStartIndex(string $empresaId): int
    {
        $empresa = Empresa::find($empresaId);
        $ws = $empresa?->settings['calendar']['week_start'] ?? 0;
        $ws = (int)$ws;
        return ($ws >= 0 && $ws <= 6) ? $ws : 0;
    }

    // devuelve [YYYY-mm-dd, YYYY-mm-dd]
    private function weekRangeForDate(string $empresaId, string $date): array
    {
        $weekStart = $this->weekStartIndex($empresaId);

        $d = now()->parse($date);
        $realWeekday = (int)$d->dayOfWeek; // 0=domingo..6=sábado
        $delta = ($realWeekday - $weekStart + 7) % 7;

        $start = $d->copy()->subDays($delta)->toDateString();
        $end = $d->copy()->subDays($delta)->addDays(6)->toDateString();

        return [$start, $end];
    }

    private function computePaidRestMinutes(string $empresaId, string $empleadoId, string $from, string $to, float $dailyHours): int
    {
        $paid = 0;

        // 1) overrides pagados tipo rest
        $ovs = EmployeeCalendarOverride::where('empresa_id',$empresaId)
            ->where('empleado_id',$empleadoId)
            ->whereBetween('date', [$from, $to])
            ->where('type','rest')
            ->where('is_paid', true)
            ->get();

        foreach ($ovs as $ov) {
            $paid += $ov->paid_minutes ?? (int)round($dailyHours * 60);
        }

        // 2) descanso semanal pagado "por default"

        return $paid;
    }
}
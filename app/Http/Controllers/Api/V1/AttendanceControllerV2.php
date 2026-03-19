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

class AttendanceControllerV2 extends Controller
{
    // EMPLEADO: entrada
    public function checkIn(Request $request)
    {
        [$u, $empresaId, $emp] = $this->authEmployee($request);

        $today = now()->toDateString();

        if (!$this->validateNetworkAccess($request, Empresa::find($empresaId))) {
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
        $day->save();

        // 🔔 PARCHE 1: Logging para check_in
        \App\Services\ActivityLogger::log(
            $empresaId,
            $u->id,
            $emp->id,
            'attendance.check_in',
            'attendance_day',
            $day->id,
            [
                'employee_name' => $emp->full_name ?? $u->name,
                'late_minutes'  => $day->late_minutes ?? null,
                'date'          => $day->date,
            ],
            $request
        );

        return response()->json(['message'=>'Entrada OK', 'day'=>$this->presentDay($day)]);
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

        $state = $this->currentState($day);
        if ($state !== 'working') {
            // bloqueamos salida si está en pausa (MVP limpio)
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

        // acciones permitidas según estado + descanso
        $actions = [
            'check_in' => !$isRest && $state === 'out',
            'break_start' => !$isRest && $state === 'working',
            'break_end' => !$isRest && $state === 'break',
            'check_out' => !$isRest && $state === 'working',
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

        $canMarkRest = ($emp->payment_type === 'daily' && !$hasCheckIn && !$restUsedThisWeek && !$isRest);

        return response()->json([
            'date' => $today,
            'is_rest_day' => $isRest,
            'state' => $isRest ? 'rest_day' : $state,
            'status' => $isRest ? 'rest_day' : $state,
            'is_paid_rest' => $isRest,
            'can_mark_rest' => $canMarkRest,
            'rest_used_this_week' => $restUsedThisWeek,
            'actions' => $actions,
            'day' => $day ? $this->presentDay($day) : null,
            'totals' => $totals,
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
        if ($day->status === 'closed') return 'closed';

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

    // ✅ FIX: retorna [worked_minutes, break_minutes] con soporte para turnos abiertos
   private function computeTotals(string $empresaId, string $dayId): array
{
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

    if (!$checkIn) {
        return [0, 0];
    }

    $effectiveCheckOut = $checkOut ?? now();

    // ✅ FIX: si hay pausa abierta, contar hasta ahora
    if ($breakStart) {
        $breakSeconds += max(0, $breakStart->diffInSeconds(now()));
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
        return [
            'id'=>$d->id,
            'empleado_id'=>$d->empleado_id,
            'date'=>$d->date?->toDateString(),
            'status'=>$d->status,
            'first_check_in_at'=>$d->first_check_in_at?->toISOString(),
            'last_check_out_at'=>$d->last_check_out_at?->toISOString(),
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
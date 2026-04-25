<?php
// app/Http/Controllers/Api/V1/PayrollController.php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AttendanceDay;
use App\Models\AttendanceEvent;
use App\Models\EmployeeCalendarOverride;
use App\Models\Empleado;
use App\Models\Empresa;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PayrollController extends Controller
{
    // ── Solo admin ────────────────────────────────────────────────────────────
    private function requireAdmin(Request $request): array
    {
        $u = $request->user();
        if ($u->role !== 'admin') {
            abort(response()->json(['message' => 'No autorizado'], 403));
        }
        return [$u, $u->empresa_id];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /nomina/periodos
    // Lista periodos de nómina de la empresa (más recientes primero)
    // ─────────────────────────────────────────────────────────────────────────
    public function index(Request $request)
    {
        [, $empresaId] = $this->requireAdmin($request);

        $query = PayrollPeriod::where('empresa_id', $empresaId);

        // Filtrar por status si se proporciona (ej: ?status=approved)
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $periods = $query->orderByDesc('week_start')
            ->paginate(12);

        return response()->json($periods);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /nomina/periodos/generar
    // Genera (o regenera) el periodo para la semana que contiene `week_date`
    // ─────────────────────────────────────────────────────────────────────────
    public function generate(Request $request)
    {
        [$u, $empresaId] = $this->requireAdmin($request);

        $data = $request->validate([
            'week_date' => ['required', 'date'], // cualquier día de la semana
        ]);

        [$weekStart, $weekEnd] = $this->weekRange($data['week_date'], $empresaId);

        // Busca periodo existente antes de la transacción
        $period = PayrollPeriod::where('empresa_id', $empresaId)
            ->where('week_start', $weekStart)
            ->first();

        if ($period && $period->status === 'approved') {
            return response()->json([
                'message' => 'Este periodo ya fue aprobado y no puede regenerarse',
                'period'  => $this->presentPeriod($period->load('entries.empleado')),
            ], 409);
        }

        // Section 2.4: toda la generación dentro de una transacción
        return DB::transaction(function () use ($empresaId, $period, $weekStart, $weekEnd) {
            if (!$period) {
                $period = PayrollPeriod::create([
                    'empresa_id' => $empresaId,
                    'week_start' => $weekStart,
                    'week_end'   => $weekEnd,
                    'status'     => 'draft',
                ]);
            }

            $rawExcluded = $period->excluded_employee_ids ?? [];
            $excluded = is_string($rawExcluded) ? json_decode($rawExcluded, true) : $rawExcluded;
            if (!is_array($excluded)) $excluded = [];

            // Recalcula entradas para todos los empleados activos
            $empleados = Empleado::where('empresa_id', $empresaId)
                ->where('status', 'active')
                ->when(!empty($excluded), fn($q) => $q->whereNotIn('id', $excluded))
                ->get();

            foreach ($empleados as $emp) {
                $this->computeEntry($empresaId, $period, $emp, $weekStart, $weekEnd);
            }

            // Recalcula totales del periodo
            $this->recalcPeriodTotals($period);

            return response()->json([
                'message' => 'Periodo generado correctamente',
                'period'  => $this->presentPeriod($period->fresh(['entries.empleado'])),
            ]);
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /nomina/periodos/{id}
    // Detalle del periodo con todas sus entradas
    // ─────────────────────────────────────────────────────────────────────────
    public function show(Request $request, string $id)
    {
        [, $empresaId] = $this->requireAdmin($request);

        $period = PayrollPeriod::where('empresa_id', $empresaId)
            ->with(['entries.empleado'])
            ->findOrFail($id);

        return response()->json($this->presentPeriod($period));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /nomina/periodos/semana?week_date=YYYY-MM-DD
    // Busca un periodo por fecha de semana (sin generarlo)
    // ─────────────────────────────────────────────────────────────────────────
    public function showByWeekDate(Request $request)
    {
        [, $empresaId] = $this->requireAdmin($request);

        $data = $request->validate([
            'week_date' => ['required', 'date'],
        ]);

        [$weekStart, $weekEnd] = $this->weekRange($data['week_date'], $empresaId);

        $period = PayrollPeriod::where('empresa_id', $empresaId)
            ->where('week_start', $weekStart)
            ->with(['entries.empleado'])
            ->first();

        if (!$period) {
            return response()->json([
                'message'    => 'No hay periodo para esta semana',
                'week_start' => $weekStart,
                'week_end'   => $weekEnd,
            ], 404);
        }

        return response()->json($this->presentPeriod($period));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PATCH /nomina/periodos/{id}/entradas/{entryId}
    // Actualiza ajuste y/o bono de una entrada
    // ─────────────────────────────────────────────────────────────────────────
    public function updateEntry(Request $request, string $periodId, string $entryId)
    {
        [$u, $empresaId] = $this->requireAdmin($request);

        $period = PayrollPeriod::where('empresa_id', $empresaId)->findOrFail($periodId);

        if ($period->status === 'approved') {
            return response()->json(['message' => 'No se puede modificar un periodo aprobado'], 409);
        }

        $entry = PayrollEntry::where('payroll_period_id', $periodId)
            ->where('empresa_id', $empresaId)
            ->findOrFail($entryId);

        $data = $request->validate([
            'adjustment_amount' => ['sometimes', 'numeric'],
            'adjustment_note'   => ['sometimes', 'nullable', 'string', 'max:200'],
            'bonus_amount'      => ['sometimes', 'numeric', 'min:0'],
            'bonus_note'        => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);

        $entry->fill($data);
        $entry->total = $entry->subtotal + ($entry->adjustment_amount ?? 0) + ($entry->bonus_amount ?? 0);
        $entry->save();

        $this->recalcPeriodTotals($period);

        return response()->json([
            'entry'  => $this->presentEntry($entry),
            'period_totals' => [
                'total_amount'      => $period->fresh()->total_amount,
                'total_adjustments' => $period->fresh()->total_adjustments,
                'total_bonuses'     => $period->fresh()->total_bonuses,
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /nomina/periodos/{periodoId}/excluir
    // Excluye o incluye un empleado de una nómina específica
    // ─────────────────────────────────────────────────────────────────────────
    public function excluirEmpleado(Request $request, string $periodoId)
    {
        [$u, $empresaId] = $this->requireAdmin($request);

        $data = $request->validate([
            'empleado_id' => ['required', 'uuid'],
            'excluir'     => ['required', 'boolean'],
        ]);

        $periodo = PayrollPeriod::where('empresa_id', $empresaId)
            ->findOrFail($periodoId);

        if ($periodo->status === 'approved') {
            return response()->json(['message' => 'No se puede modificar una nómina aprobada'], 409);
        }

        $rawExcluded = $periodo->excluded_employee_ids ?? [];
        $excluded = is_string($rawExcluded) ? json_decode($rawExcluded, true) : $rawExcluded;
        if (!is_array($excluded)) $excluded = [];

        if ($data['excluir']) {
            if (!in_array($data['empleado_id'], $excluded)) {
                $excluded[] = $data['empleado_id'];
            }
        } else {
            $excluded = array_values(array_filter(
                $excluded,
                fn($id) => $id !== $data['empleado_id']
            ));
        }

        $periodo->excluded_employee_ids = $excluded;
        $periodo->save();

        return response()->json([
            'message'               => $data['excluir'] ? 'Empleado excluido de la nómina' : 'Empleado incluido en la nómina',
            'excluded_employee_ids' => $excluded,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /nomina/periodos/{id}/aprobar
    // Aprueba y cierra el periodo
    // ─────────────────────────────────────────────────────────────────────────
    public function approve(Request $request, string $id)
    {
        [$u, $empresaId] = $this->requireAdmin($request);

        $period = PayrollPeriod::where('empresa_id', $empresaId)->findOrFail($id);

        if ($period->status === 'approved') {
            return response()->json(['message' => 'Ya estaba aprobado'], 409);
        }

        // Section 2.4: aprobación atómica
        return DB::transaction(function () use ($period, $u) {
            $period->update([
                'status'      => 'approved',
                'approved_by' => $u->id,
                'approved_at' => now(),
            ]);

            return response()->json([
                'message' => 'Nómina aprobada',
                'period'  => $this->presentPeriod($period->fresh(['entries.empleado'])),
            ]);
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PATCH /nomina/periodos/{id}
    // Actualiza las notas del periodo
    // ─────────────────────────────────────────────────────────────────────────
    public function updateNotes(Request $request, string $id)
    {
        [$u, $empresaId] = $this->requireAdmin($request);

        $period = PayrollPeriod::where('empresa_id', $empresaId)->findOrFail($id);

        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $period->update(['notes' => $data['notes']]);

        return response()->json([
            'message' => 'Notas actualizadas',
            'period'  => $this->presentPeriod($period->fresh(['entries.empleado'])),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /nomina/periodos/{id}/exportar
    // Devuelve JSON estructurado para exportar (PDF/Excel en frontend)
    // ─────────────────────────────────────────────────────────────────────────
    public function export(Request $request, string $id)
    {
        [, $empresaId] = $this->requireAdmin($request);

        $period = PayrollPeriod::where('empresa_id', $empresaId)
            ->with(['entries.empleado', 'approvedBy'])
            ->findOrFail($id);

        return response()->json($this->presentPeriod($period));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers internos
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Calcula o actualiza la entrada de un empleado para el periodo dado.
     */
    private function computeEntry(
        string $empresaId,
        PayrollPeriod $period,
        Empleado $emp,
        string $weekStart,
        string $weekEnd
    ): PayrollEntry {
        $paymentType = $emp->payment_type ?? 'hourly';
        $rate        = $paymentType === 'hourly' ? ($emp->hourly_rate ?? 0) : ($emp->daily_rate ?? 0);

        $units        = 0;
        $restDaysPaid = 0;
        $subtotal     = 0;

        // Trae los attendance_days de la semana para este empleado (solo se usa count para 'daily')
        $days = AttendanceDay::where('empresa_id', $empresaId)
            ->where('empleado_id', $emp->id)
            ->whereBetween('date', [$weekStart, $weekEnd])
            ->get()
            ->keyBy(fn($d) => $d->date->toDateString());

        if ($paymentType === 'hourly') {
            $units    = $this->calcularHorasSemanales($emp, $weekStart, $weekEnd);
            $subtotal = round($units * $rate, 2);

        } else {
            // Días de trabajo asistidos (status closed o open con check-in)
            $workedDays = $days->filter(fn($d) => $d->first_check_in_at !== null)->count();

            // Días de descanso pagados (override tipo rest) dentro de la semana
            $paidRestDays = EmployeeCalendarOverride::where('empresa_id', $empresaId)
                ->where('empleado_id', $emp->id)
                ->whereBetween('date', [$weekStart, $weekEnd])
                ->where('type', 'rest')
                ->count();

            // Máximo 1 día de descanso pagado por semana
            $restDaysPaid = min($paidRestDays, 1);
            $units        = $workedDays;
            $subtotal     = round(($workedDays + $restDaysPaid) * $rate, 2);
        }

        // Busca entrada existente para preservar ajustes manuales
        $entry = PayrollEntry::where('payroll_period_id', $period->id)
            ->where('empleado_id', $emp->id)
            ->first();

        // ⏰ Retardos del mes completo que contiene el periodo
        $monthStart = Carbon::parse($weekStart)->startOfMonth()->toDateString();
        $monthEnd   = Carbon::parse($weekStart)->endOfMonth()->toDateString();

        $tardiness = AttendanceDay::where('empresa_id', $empresaId)
            ->where('empleado_id', $emp->id)
            ->whereBetween('date', [$monthStart, $monthEnd])
            ->where('late_minutes', '>', 0)
            ->count();

        // 3 o más retardos en el mes → no se paga el descanso semanal
        if ($tardiness >= 3) {
            $restDaysPaid = 0;
        }

        // Faltas de la semana: días sin check-in (excluyendo descansos y festivos)
        $absences = 0;
        $dateRange = collect();
        $cursor = Carbon::parse($weekStart);
        $weekEndDate = Carbon::parse($weekEnd);
        while ($cursor->lte($weekEndDate)) {
            $dateRange->push($cursor->toDateString());
            $cursor->addDay();
        }
        $checkedInDates = $days->filter(fn($d) => $d->first_check_in_at !== null)->keys();
        $restOrDayOff = EmployeeCalendarOverride::where('empresa_id', $empresaId)
            ->where('empleado_id', $emp->id)
            ->whereBetween('date', [$weekStart, $weekEnd])
            ->pluck('date')
            ->map(fn($d) => Carbon::parse($d)->toDateString())
            ->toArray();
        $absences = $dateRange->filter(function($date) use ($checkedInDates, $restOrDayOff, $days) {
            if (in_array($date, $restOrDayOff)) return false;
            $dayRecord = $days->get($date);
            if ($dayRecord && $dayRecord->status === 'day_off') return false;
            if ($checkedInDates->contains($date)) return false;
            return true;
        })->count();

        $adjustmentAmount = $entry?->adjustment_amount ?? 0;
        $adjustmentNote   = $entry?->adjustment_note ?? null;
        $bonusAmount      = $entry?->bonus_amount ?? 0;
        $bonusNote        = $entry?->bonus_note ?? null;

        $total = $subtotal + $adjustmentAmount + $bonusAmount;

        $data = [
            'empresa_id'        => $empresaId,
            'payroll_period_id' => $period->id,
            'empleado_id'       => $emp->id,
            'payment_type'      => $paymentType,
            'rate'              => $rate,
            'units'             => $units,
            'rest_days_paid'    => $restDaysPaid,
            'tardiness_count'   => $tardiness,
            'absences_count'    => $absences,
            'subtotal'          => $subtotal,
            'adjustment_amount' => $adjustmentAmount,
            'adjustment_note'   => $adjustmentNote,
            'bonus_amount'      => $bonusAmount,
            'bonus_note'        => $bonusNote,
            'total'             => $total,
        ];

        if ($entry) {
            $entry->update($data);
            return $entry;
        }

        return PayrollEntry::create($data);
    }

    /**
     * Calcula horas trabajadas para un empleado en la semana corrigiendo bugs de minutos.
     */
    private function calcularHorasSemanales(
        Empleado $emp,
        string $weekStart,
        string $weekEnd
    ): float {
        $days = AttendanceDay::where('empresa_id', $emp->empresa_id)
            ->where('empleado_id', $emp->id)
            ->whereBetween('date', [$weekStart, $weekEnd])
            ->whereNotNull('first_check_in_at')
            ->whereNotNull('last_check_out_at')
            ->where('status', '!=', 'day_off') // excluir días de descanso
            ->get();

        $totalMinutes = 0;

        foreach ($days as $day) {
            // Validar que check_out sea después de check_in
            if ($day->last_check_out_at <= $day->first_check_in_at) continue;

            $dayMinutes = $day->first_check_in_at->diffInMinutes($day->last_check_out_at);

            // Descontar tiempo de comida completado
            if ($day->lunch_start_at && $day->lunch_end_at &&
                $day->lunch_end_at > $day->lunch_start_at) {
                $lunchMinutes = $day->lunch_start_at->diffInMinutes($day->lunch_end_at);
                $dayMinutes -= min($lunchMinutes, 60); // máximo 1 hora de comida
            }

            // Sanitizar: máximo 14 horas por día (840 minutos)
            $dayMinutes = max(0, min($dayMinutes, 840));
            $totalMinutes += $dayMinutes;
        }

        if ($totalMinutes > 6720) { // 112 horas en minutos
            \Illuminate\Support\Facades\Log::warning("Horas anómalas para empleado {$emp->id}: " .
                round($totalMinutes/60, 1) . "h en semana {$weekStart}");
            $totalMinutes = 0;
        }

        // Retornar horas con 2 decimales
        return round($totalMinutes / 60, 2);
    }

    /**
     * Recalcula y guarda los totales del periodo.
     */
    private function recalcPeriodTotals(PayrollPeriod $period): void
    {
        $rawExcluded = $period->excluded_employee_ids ?? [];
        $excluded = is_string($rawExcluded) ? json_decode($rawExcluded, true) : $rawExcluded;
        if (!is_array($excluded)) $excluded = [];

        $entries = PayrollEntry::where('payroll_period_id', $period->id)
            ->when(!empty($excluded), fn($q) => $q->whereNotIn('empleado_id', $excluded))
            ->get();

        $period->update([
            'total_amount'      => $entries->sum('total'),
            'total_adjustments' => $entries->sum('adjustment_amount'),
            'total_bonuses'     => $entries->sum('bonus_amount'),
        ]);
    }

    private function weekStartIndex(string $empresaId): int
    {
        $empresa = Empresa::find($empresaId);
        $ws = $empresa?->settings['calendar']['week_start'] ?? 0;
        return (int)$ws;
    }

    /**
     * Calcula dinámicamente el inicio de semana de la empresa.
     */
    private function weekRange(string $date, string $empresaId): array
    {
        $weekStart = $this->weekStartIndex($empresaId);
        $d = Carbon::parse($date);
        $realWeekday = (int)$d->dayOfWeek; // 0=domingo..6=sábado
        $delta = ($realWeekday - $weekStart + 7) % 7;

        $start = $d->copy()->subDays($delta)->toDateString();
        $end = $d->copy()->subDays($delta)->addDays(6)->toDateString();

        return [$start, $end];
    }

    private function presentPeriod(PayrollPeriod $p): array
    {
        $entries = $p->relationLoaded('entries')
            ? $p->entries->map(fn($e) => $this->presentEntry($e))->values()
            : [];

        return [
            'id'                => $p->id,
            'week_start'        => $p->week_start?->toDateString(),
            'week_end'          => $p->week_end?->toDateString(),
            'status'            => $p->status,
            'notes'             => $p->notes,
            'total_amount'      => $p->total_amount,
            'total_adjustments' => $p->total_adjustments,
            'total_bonuses'     => $p->total_bonuses,
            'approved_at'       => $p->approved_at?->toISOString(),
            'excluded_employee_ids' => is_string($p->excluded_employee_ids) ? json_decode($p->excluded_employee_ids, true) : ($p->excluded_employee_ids ?? []),
            'entries'           => $entries,
        ];
    }

    private function presentEntry(PayrollEntry $e): array
    {
        $emp = $e->relationLoaded('empleado') ? $e->empleado : null;
        return [
            'id'                => $e->id,
            'empleado_id'       => $e->empleado_id,
            'empleado_name'     => $emp?->full_name ?? '—',
            'empleado_role'     => $emp?->position_title ?? null,
            'payment_type'      => $e->payment_type,
            'rate'              => $e->rate,
            'units'             => $e->units,
            'rest_days_paid'    => $e->rest_days_paid,
            'tardiness_count'   => $e->tardiness_count ?? 0,
            'absences_count'    => $e->absences_count ?? 0,
            'penalty_active'    => ($e->tardiness_count ?? 0) >= 3,
            'subtotal'          => $e->subtotal,
            'adjustment_amount' => $e->adjustment_amount,
            'adjustment_note'   => $e->adjustment_note,
            'bonus_amount'      => $e->bonus_amount,
            'bonus_note'        => $e->bonus_note,
            'total'             => $e->total,
        ];
    }
}
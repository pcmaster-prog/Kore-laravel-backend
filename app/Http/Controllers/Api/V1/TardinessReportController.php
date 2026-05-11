<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AttendanceDay;
use App\Models\Empleado;
use App\Models\GeneratedAbsence;
use App\Models\TardinessConfig;
use Illuminate\Http\Request;

class TardinessReportController extends Controller
{
    /**
     * GET /api/v1/retardos/resumen-mes?month=2026-04
     * Reporte mensual de retardos para todos los empleados de la empresa.
     */
    public function monthlySummary(Request $request)
    {
        $u = $request->user();
        $empresaId = $u->empresa_id;

        $month = $request->input('month', now()->format('Y-m'));

        // Validate month format
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            return response()->json(['message' => 'Formato de mes inválido. Use YYYY-MM.'], 422);
        }

        // Get config (lazy init)
        $config = TardinessConfig::firstOrCreate(
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

        // Get all employees for this empresa
        $empleados = Empleado::where('empresa_id', $empresaId)
            ->whereNull('deleted_at')
            ->get();

        $summary = [];

        foreach ($empleados as $emp) {
            // Get all late days for this employee in this month
            $lateDays = AttendanceDay::where('empresa_id', $empresaId)
                ->where('empleado_id', $emp->id)
                ->where('date', 'LIKE', "{$month}%")
                ->where('late_minutes', '>', 0)
                ->get();

            if ($lateDays->isEmpty()) {
                continue; // Skip employees with no lates
            }

            $totalLateMinutes = $lateDays->sum('late_minutes');

            // Count generated absences for this employee in this period
            $absencesGenerated = GeneratedAbsence::where('empleado_id', $emp->id)
                ->where('period_key', $month)
                ->count();

            $restDayPenalized = GeneratedAbsence::where('empleado_id', $emp->id)
                ->where('period_key', $month)
                ->where('affects_rest_day_payment', true)
                ->exists();

            $summary[] = [
                'empleado_id'        => $emp->id,
                'empleado_name'      => $emp->full_name,
                'total_lates'        => $lateDays->count(),
                'total_late_minutes' => $totalLateMinutes,
                'absences_generated' => $absencesGenerated,
                'rest_day_penalized' => $restDayPenalized,
                'dates'              => $lateDays->pluck('date')->map(fn($d) => $d->toDateString())->values()->toArray(),
            ];
        }

        return response()->json([
            'period'  => $month,
            'config'  => [
                'grace_period_minutes' => $config->grace_period_minutes,
                'lates_to_absence'     => $config->lates_to_absence,
            ],
            'summary' => $summary,
        ]);
    }

    /**
     * GET /api/v1/retardos/empleado/{empleado}?month=2026-04
     * Detalle de retardos por empleado en un mes específico.
     */
    public function employeeDetail(Request $request, string $empleado)
    {
        $u = $request->user();
        $empresaId = $u->empresa_id;

        $month = $request->input('month', now()->format('Y-m'));

        // Validate month format
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            return response()->json(['message' => 'Formato de mes inválido. Use YYYY-MM.'], 422);
        }

        // Verify employee belongs to the same empresa
        $emp = Empleado::where('empresa_id', $empresaId)
            ->where('id', $empleado)
            ->firstOrFail();

        // Get all late days for this employee
        $lateDays = AttendanceDay::where('empresa_id', $empresaId)
            ->where('empleado_id', $emp->id)
            ->where('date', 'LIKE', "{$month}%")
            ->where('late_minutes', '>', 0)
            ->orderBy('date')
            ->get();

        // Get config for absence threshold
        $config = TardinessConfig::firstOrCreate(
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

        // Check which dates have had absences generated
        $absences = GeneratedAbsence::where('empleado_id', $emp->id)
            ->where('period_key', $month)
            ->get();

        // Determine which lates were "converted" — all lates contribute once threshold is reached
        $lateCount = $lateDays->count();
        $thresholdReached = $lateCount >= $config->lates_to_absence;

        $lates = $lateDays->map(function ($day) use ($thresholdReached) {
            return [
                'date'                  => $day->date->toDateString(),
                'late_minutes'          => $day->late_minutes,
                'converted_to_absence'  => $thresholdReached,
            ];
        })->values();

        return response()->json([
            'empleado_id'   => $emp->id,
            'empleado_name' => $emp->full_name,
            'period'        => $month,
            'lates'         => $lates,
            'absences'      => $absences->map(fn($a) => [
                'id'                       => $a->id,
                'period_key'               => $a->period_key,
                'type'                     => $a->type,
                'affects_rest_day_payment' => $a->affects_rest_day_payment,
                'note'                     => $a->note,
                'created_at'               => $a->created_at?->toISOString(),
            ])->values(),
        ]);
    }
}

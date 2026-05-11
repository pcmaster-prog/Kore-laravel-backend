<?php

namespace App\Services;

use App\Models\AttendanceDay;
use App\Models\AttendanceEvent;
use App\Models\Empleado;
use App\Models\EmployeeCalendarOverride;
use App\Models\Empresa;
use Carbon\Carbon;

class AttendanceService
{
    /**
     * Calcula minutos trabajados y de pausa para un día de asistencia.
     * Respeta ajustes manuales del admin (first_check_in_at / last_check_out_at).
     */
    public static function computeDayTotals(AttendanceDay $day): array
    {
        $events = AttendanceEvent::where('empresa_id', $day->empresa_id)
            ->where('attendance_day_id', $day->id)
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
                $breakSeconds += max(0, $breakStart->diffInSeconds($e->occurred_at));
                $breakStart = null;
            }
        }

        // Admin overrides tienen prioridad sobre los eventos originales
        if ($day->first_check_in_at) $checkIn = $day->first_check_in_at;
        if ($day->last_check_out_at) $checkOut = $day->last_check_out_at;

        if (!$checkIn) {
            return ['worked_minutes' => 0, 'break_minutes' => 0];
        }

        $effectiveCheckOut = $checkOut ?? now();

        // Si hay pausa abierta, contar hasta el final del turno o hasta ahora
        if ($breakStart) {
            $breakEndLimit = min(now(), $effectiveCheckOut);
            if ($breakStart < $breakEndLimit) {
                $breakSeconds += max(0, $breakStart->diffInSeconds($breakEndLimit));
            }
        }

        $totalSeconds  = max(0, $checkIn->diffInSeconds($effectiveCheckOut));
        $workedSeconds = max(0, $totalSeconds - $breakSeconds);

        return [
            'worked_minutes' => (int) round($workedSeconds / 60),
            'break_minutes'  => (int) round($breakSeconds  / 60),
        ];
    }

    /**
     * Determina si una fecha es día de descanso para un empleado.
     * Considera overrides de calendario y rest_weekday configurado.
     */
    public static function isRestDay(string $empresaId, string $empleadoId, string $date): bool
    {
        $ov = EmployeeCalendarOverride::where('empresa_id', $empresaId)
            ->where('empleado_id', $empleadoId)
            ->where('date', $date)
            ->first();

        if ($ov) return $ov->type === 'rest';

        $emp = Empleado::where('empresa_id', $empresaId)->where('id', $empleadoId)->first();
        if (!$emp || $emp->rest_weekday === null) return false;

        $weekStart = self::weekStartIndex($empresaId);

        $realWeekday = (int) now()->parse($date)->dayOfWeek;
        $relative = ($realWeekday - $weekStart + 7) % 7;

        return $relative === (int) $emp->rest_weekday;
    }

    /**
     * Calcula minutos de retardo comparando la hora real de entrada
     * con la hora programada (más 1 minuto de tolerancia para visualización).
     */
    public static function calculateLateMinutes(?Carbon $checkInTime, string $checkInTimeStr): int
    {
        if (!$checkInTime || !$checkInTimeStr) return 0;

        $scheduled = Carbon::parse($checkInTime->toDateString() . ' ' . $checkInTimeStr);
        if ($checkInTime->greaterThan($scheduled->copy()->addMinute())) {
            return (int) ceil($checkInTime->diffInMinutes($scheduled));
        }

        return 0;
    }

    /**
     * Índice de inicio de semana configurado para la empresa (0=domingo..6=sábado).
     */
    public static function weekStartIndex(string $empresaId): int
    {
        $empresa = Empresa::find($empresaId);
        $ws = $empresa?->settings['calendar']['week_start'] ?? 0;
        $ws = (int) $ws;
        return ($ws >= 0 && $ws <= 6) ? $ws : 0;
    }

    /**
     * Devuelve el rango de semana [YYYY-mm-dd, YYYY-mm-dd] para una fecha dada.
     */
    public static function weekRangeForDate(string $empresaId, string $date): array
    {
        $weekStart = self::weekStartIndex($empresaId);

        $d = now()->parse($date);
        $realWeekday = (int) $d->dayOfWeek;
        $delta = ($realWeekday - $weekStart + 7) % 7;

        $start = $d->copy()->subDays($delta)->toDateString();
        $end   = $d->copy()->subDays($delta)->addDays(6)->toDateString();

        return [$start, $end];
    }

    /**
     * Suma los minutos pagados de descanso en un rango de fechas.
     */
    public static function computePaidRestMinutes(string $empresaId, string $empleadoId, string $from, string $to, float $dailyHours): int
    {
        $paid = 0;

        $ovs = EmployeeCalendarOverride::where('empresa_id', $empresaId)
            ->where('empleado_id', $empleadoId)
            ->whereBetween('date', [$from, $to])
            ->where('type', 'rest')
            ->where('is_paid', true)
            ->get();

        foreach ($ovs as $ov) {
            $paid += $ov->paid_minutes ?? (int) round($dailyHours * 60);
        }

        return $paid;
    }
}

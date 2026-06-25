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

        $checkIn = null;
        $checkOut = null;
        $breakStart = null;
        $breakSeconds = 0;

        foreach ($events as $e) {
            if ($e->type === 'check_in') {
                $checkIn = $checkIn ?? $e->occurred_at;
            }
            if ($e->type === 'check_out') {
                $checkOut = $e->occurred_at;
            }

            if ($e->type === 'break_start') {
                $breakStart = $e->occurred_at;
            }
            if ($e->type === 'break_end' && $breakStart) {
                $breakSeconds += max(0, $breakStart->diffInSeconds($e->occurred_at));
                $breakStart = null;
            }
        }

        // Admin overrides tienen prioridad sobre los eventos originales
        if ($day->first_check_in_at) {
            $checkIn = $day->first_check_in_at;
        }
        if ($day->last_check_out_at) {
            $checkOut = $day->last_check_out_at;
        }

        if (! $checkIn) {
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

        $empresa = Empresa::find($day->empresa_id);
        $settings = is_array($empresa?->settings) ? $empresa->settings : [];
        $breakPausesClock = $settings['operativo']['break_pauses_clock'] ?? true;

        $totalSeconds = max(0, $checkIn->diffInSeconds($effectiveCheckOut));

        if ($breakPausesClock) {
            $workedSeconds = max(0, $totalSeconds - $breakSeconds);
        } else {
            $workedSeconds = $totalSeconds;
        }

        return [
            'worked_minutes' => (int) round($workedSeconds / 60),
            'break_minutes' => (int) round($breakSeconds / 60),
        ];
    }

    /**
     * Obtiene el horario configurado para un día específico de la semana.
     * Considera week_schedule si existe, o fallback al horario base.
     */
    public static function getDaySchedule(string $empresaId, string $date): ?array
    {
        $empresa = Empresa::find($empresaId);
        $settings = is_array($empresa?->settings) ? $empresa->settings : [];
        $operativo = $settings['operativo'] ?? [];
        $weekSchedule = $operativo['week_schedule'] ?? null;

        $weekday = (int) Carbon::parse($date)->dayOfWeek;

        if ($weekSchedule && is_array($weekSchedule)) {
            $daySchedule = collect($weekSchedule)->firstWhere('weekday', $weekday);
            if ($daySchedule) {
                return [
                    'check_in_time' => $daySchedule['check_in_time'] ?? null,
                    'check_out_time' => $daySchedule['check_out_time'] ?? null,
                    'is_working_day' => (bool) ($daySchedule['is_working_day'] ?? true),
                ];
            }
        }

        // Fallback al horario base
        $checkInTime = $operativo['check_in_time'] ?? null;
        if (! $checkInTime) {
            return null;
        }

        return [
            'check_in_time' => $checkInTime,
            'check_out_time' => $operativo['check_out_time'] ?? null,
            'is_working_day' => true,
        ];
    }

    /**
     * Determina si una fecha es día no laborable según week_schedule.
     */
    public static function isNonWorkingDay(string $empresaId, string $date): bool
    {
        $schedule = self::getDaySchedule($empresaId, $date);

        return $schedule !== null && $schedule['is_working_day'] === false;
    }

    /**
     * Calcula la hora estimada de salida considerando:
     * - hora real de entrada
     * - max_hours configurado
     * - exceso de comida (meal_overtime_minutes)
     * - descansos (si pausan reloj)
     *
     * @deprecated Usar calculateOfficialExitTime o calculateRequiredExitTime
     */
    public static function calculateExpectedExitTime(AttendanceDay $day): ?Carbon
    {
        if (! $day->first_check_in_at) {
            return null;
        }

        $empresa = Empresa::find($day->empresa_id);
        $settings = is_array($empresa?->settings) ? $empresa->settings : [];
        $operativo = $settings['operativo'] ?? [];
        $maxHours = (int) ($operativo['max_hours'] ?? 8);
        $mealDuration = (int) ($operativo['meal_duration_minutes'] ?? 30);
        $breakPausesClock = (bool) ($operativo['break_pauses_clock'] ?? true);

        $baseMinutes = $maxHours * 60;

        // Sumar exceso de comida
        $mealOvertime = (int) ($day->meal_overtime_minutes ?? 0);

        // Calcular minutos de break para compensar si pausa reloj
        $breakCompensation = 0;
        if ($breakPausesClock) {
            $totals = self::computeDayTotals($day);
            $breakCompensation = $totals['break_minutes'];
        }

        $totalShiftMinutes = $baseMinutes + $mealDuration + $mealOvertime + $breakCompensation;

        return $day->first_check_in_at->copy()->addMinutes($totalShiftMinutes);
    }

    /**
     * Calcula la hora oficial de salida basada en el horario programado
     * del día de la semana (check_out_time de week_schedule).
     * No considera la hora real de entrada ni retardos.
     */
    public static function calculateOfficialExitTime(AttendanceDay $day): ?Carbon
    {
        $schedule = self::getDaySchedule($day->empresa_id, $day->date->toDateString());

        if (! $schedule || ! $schedule['is_working_day'] || ! $schedule['check_out_time']) {
            return null;
        }

        return Carbon::parse($day->date->toDateString().' '.$schedule['check_out_time']);
    }

    /**
     * Calcula la hora de salida requerida que el empleado debe cumplir
     * para completar su jornada. Considera:
     * - hora oficial de salida
     * - minutos de retardo (late_minutes)
     * - exceso de comida (meal_overtime_minutes)
     * - descansos (si pausan reloj)
     */
    public static function calculateRequiredExitTime(AttendanceDay $day): ?Carbon
    {
        $officialExit = self::calculateOfficialExitTime($day);
        if (! $officialExit) {
            return null;
        }

        $lateMinutes = (int) ($day->late_minutes ?? 0);
        $mealOvertime = (int) ($day->meal_overtime_minutes ?? 0);

        $empresa = Empresa::find($day->empresa_id);
        $settings = is_array($empresa?->settings) ? $empresa->settings : [];
        $operativo = $settings['operativo'] ?? [];
        $breakPausesClock = (bool) ($operativo['break_pauses_clock'] ?? true);

        $breakCompensation = 0;
        if ($breakPausesClock) {
            $totals = self::computeDayTotals($day);
            $breakCompensation = $totals['break_minutes'];
        }

        return $officialExit->copy()->addMinutes($lateMinutes + $mealOvertime + $breakCompensation);
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

        if ($ov) {
            return $ov->type === 'rest';
        }

        $emp = Empleado::where('empresa_id', $empresaId)->where('id', $empleadoId)->first();
        if (! $emp || $emp->rest_weekday === null) {
            return false;
        }

        $weekStart = self::weekStartIndex($empresaId);

        $realWeekday = (int) now()->parse($date)->dayOfWeek;
        $relative = ($realWeekday - $weekStart + 7) % 7;

        return $relative === (int) $emp->rest_weekday;
    }

    /**
     * Obtiene la hora de entrada efectiva de un empleado para una fecha.
     * Si el empleado tiene check_in_time propio, lo usa; de lo contrario
     * fallback al horario de la empresa.
     */
    public static function getEmployeeCheckInTime(string $empresaId, string $empleadoId, string $date): ?string
    {
        $emp = Empleado::where('empresa_id', $empresaId)->where('id', $empleadoId)->first();
        if ($emp && $emp->check_in_time) {
            return is_string($emp->check_in_time)
                ? substr($emp->check_in_time, 0, 5)
                : $emp->check_in_time->format('H:i');
        }

        $schedule = self::getDaySchedule($empresaId, $date);

        return $schedule['check_in_time'] ?? null;
    }

    /**
     * Calcula minutos de retardo comparando la hora real de entrada
     * con la hora programada (más 1 minuto de tolerancia para visualización).
     */
    public static function calculateLateMinutes(?Carbon $checkInTime, string $checkInTimeStr): int
    {
        if (! $checkInTime || ! $checkInTimeStr) {
            return 0;
        }

        $scheduled = Carbon::parse($checkInTime->toDateString().' '.$checkInTimeStr);
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
        $end = $d->copy()->subDays($delta)->addDays(6)->toDateString();

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

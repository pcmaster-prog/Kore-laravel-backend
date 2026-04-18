<?php
// AutoCloseAttendance: cierra automáticamente la jornada de todos los empleados que siguen con día abierto.
// Se ejecuta vía scheduler según la configuración de cada empresa (auto_close_time + auto_close_weekday).
namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\AttendanceDay;
use App\Models\Empresa;
use Carbon\Carbon;

class AutoCloseAttendance extends Command
{
    protected $signature   = 'attendance:auto-close';
    protected $description = 'Cierra automáticamente los días de asistencia abiertos según la configuración de cada empresa';

    public function handle(): void
    {
        $today     = now()->toDateString();
        $nowTime   = now()->format('H:i');
        $weekday   = (int) now()->dayOfWeek; // 0=Dom ... 6=Sáb

        $empresas = Empresa::all();

        foreach ($empresas as $empresa) {
            $settings  = is_array($empresa->settings) ? $empresa->settings : [];
            $operativo = $settings['operativo'] ?? [];

            $autoCloseTime    = $operativo['auto_close_time']    ?? null;   // "17:00"
            $autoCloseWeekday = $operativo['auto_close_weekday'] ?? null;   // 0-6 o null = todos los días

            // Solo ejecutar si la empresa tiene habilitado el cierre automático
            if (!$autoCloseTime) {
                continue;
            }

            // Verificar si aplica para hoy según el día configurado
            if ($autoCloseWeekday !== null && (int)$autoCloseWeekday !== $weekday) {
                continue;
            }

            // Verificar que ya sea la hora configurada (con 1 min de margen)
            if ($nowTime < $autoCloseTime) {
                continue;
            }

            // Cerrar todos los días abiertos del hoy para esta empresa
            $openDays = AttendanceDay::where('empresa_id', $empresa->id)
                ->where('date', $today)
                ->whereIn('status', ['open'])
                ->whereNotNull('first_check_in_at')
                ->whereNull('last_check_out_at')
                ->get();

            $closed = 0;
            foreach ($openDays as $day) {
                $closeTime = Carbon::parse($today . ' ' . $autoCloseTime);
                $day->last_check_out_at = $closeTime;
                $day->status = 'closed';
                $day->save();

                \App\Services\ActivityLogger::log(
                    $empresa->id,
                    null,
                    $day->empleado_id,
                    'attendance.auto_closed',
                    'attendance_day',
                    $day->id,
                    [
                        'auto_close_time' => $autoCloseTime,
                        'fecha'           => $today,
                    ],
                    null
                );

                $closed++;
            }

            if ($closed > 0) {
                $this->info("Empresa [{$empresa->id}]: {$closed} días cerrados automáticamente a las {$autoCloseTime}.");
            }
        }

        $this->info('Auto-cierre completado.');
    }
}

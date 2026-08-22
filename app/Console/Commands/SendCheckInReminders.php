<?php

namespace App\Console\Commands;

use App\Models\AttendanceDay;
use App\Models\Empleado;
use App\Models\Empresa;
use App\Models\Holiday;
use App\Services\AttendanceService;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SendCheckInReminders extends Command
{
    protected $signature = 'attendance:check-in-reminders';

    protected $description = 'Push "¿Olvidaste marcar tu entrada?" a quien no ha marcado 10 min después de su hora de entrada';

    /** Minutos después de la hora de entrada a partir de los cuales se recuerda. */
    private const DELAY_MINUTES = 10;

    /** Ventana máxima (minutos después de la entrada) para enviar; evita avisos tardíos. */
    private const WINDOW_MINUTES = 60;

    public function handle(): void
    {
        $today = now()->toDateString();
        $now = now();
        $notifier = app(NotificationService::class);
        $sent = 0;

        foreach (Empresa::all() as $empresa) {
            if (AttendanceService::isNonWorkingDay($empresa->id, $today)) {
                continue;
            }

            if (Holiday::where('empresa_id', $empresa->id)->whereDate('date', $today)->exists()) {
                continue;
            }

            $empleados = Empleado::where('empresa_id', $empresa->id)
                ->where('status', 'active')
                ->whereNotNull('user_id')
                ->whereHas('user', fn ($q) => $q->where('is_active', true)->whereNotIn('role', ['admin', 'supervisor']))
                ->get();

            foreach ($empleados as $emp) {
                $checkInTime = AttendanceService::getEmployeeCheckInTime($empresa->id, $emp->id, $today);
                if (! $checkInTime) {
                    continue;
                }

                $scheduled = Carbon::parse($today.' '.$checkInTime);
                $minutesLate = $scheduled->diffInMinutes($now, false);
                if ($minutesLate < self::DELAY_MINUTES || $minutesLate > self::WINDOW_MINUTES) {
                    continue;
                }

                if (AttendanceService::isRestDay($empresa->id, $emp->id, $today)) {
                    continue;
                }

                $alreadyIn = AttendanceDay::where('empresa_id', $empresa->id)
                    ->where('empleado_id', $emp->id)
                    ->whereDate('date', $today)
                    ->whereNotNull('first_check_in_at')
                    ->exists();
                if ($alreadyIn) {
                    continue;
                }

                // Un solo aviso por empleado y día (sin tocar la tabla de asistencia)
                if (! Cache::add("checkin_reminder:{$emp->id}:{$today}", 1, now()->addDay())) {
                    continue;
                }

                try {
                    $notifier->sendToUser(
                        userId: $emp->user_id,
                        title: '⏰ ¿Olvidaste marcar tu entrada?',
                        body: 'Tu hora de entrada era a las '.$scheduled->format('H:i').'. Si ya estás trabajando, marca tu entrada ahora.',
                        data: ['type' => 'check_in_reminder', 'scheduled_time' => $checkInTime]
                    );
                    $sent++;
                } catch (\Throwable $e) {
                    Log::warning("Error enviando recordatorio de entrada a {$emp->user_id}: ".$e->getMessage());
                }
            }
        }

        $this->info("Recordatorios de entrada enviados: {$sent}.");
    }
}

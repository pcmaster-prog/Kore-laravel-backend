<?php

namespace App\Listeners;

use App\Events\AttendanceCheckedIn;
use App\Jobs\SendPushNotificationToManagers;
use App\Models\Empleado;
use Illuminate\Support\Facades\Log;

class SendCheckInNotificationToManagers
{
    /**
     * Handle the event.
     */
    public function handle(AttendanceCheckedIn $event): void
    {
        $day = $event->day;

        $empleado = Empleado::find($day->empleado_id);

        if (! $empleado) {
            Log::warning('SendCheckInNotificationToManagers: empleado no encontrado', [
                'empleado_id' => $day->empleado_id,
            ]);
            return;
        }

        try {
            SendPushNotificationToManagers::dispatch(
                $day->empresa_id,
                'Nuevo check-in registrado',
                "{$empleado->full_name} ha registrado su entrada.",
                [
                    'type'              => 'check_in',
                    'attendance_day_id' => $day->id,
                    'empleado_id'       => $empleado->id,
                ]
            );
        } catch (\Throwable $e) {
            Log::error('SendCheckInNotificationToManagers listener failed', [
                'attendance_day_id' => $day->id,
                'error'             => $e->getMessage(),
            ]);
        }
    }
}

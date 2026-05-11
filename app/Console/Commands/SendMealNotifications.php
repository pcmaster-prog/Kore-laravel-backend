<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\MealSchedule;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;

class SendMealNotifications extends Command
{
    protected $signature   = 'meals:notify';
    protected $description = 'Envía notificaciones push a los empleados cuya hora de comida coincide con la hora actual';

    public function handle(): void
    {
        $now = now()->format('H:i');

        $schedules = MealSchedule::where('meal_start_time', 'LIKE', $now . '%')
            ->with('employee')
            ->get();

        if ($schedules->isEmpty()) {
            $this->info("No hay horarios de comida para las {$now}.");
            return;
        }

        $notifier = app(NotificationService::class);
        $sent = 0;

        foreach ($schedules as $schedule) {
            $empleado = $schedule->employee;

            if (!$empleado || !$empleado->user_id) {
                continue;
            }

            $user = \App\Models\User::find($empleado->user_id);

            if (!$user || !$user->is_active) {
                continue;
            }

            try {
                $notifier->sendToUser(
                    userId: $user->id,
                    title: '🍽️ Hora de comida',
                    body: "Tu horario de comida comienza ahora ({$now}). Tienes {$schedule->duration_minutes} minutos.",
                    data: [
                        'type'             => 'meal_reminder',
                        'duration_minutes' => (string) $schedule->duration_minutes,
                    ]
                );
                $sent++;
            } catch (\Throwable $e) {
                Log::warning("Error enviando notificación de comida a usuario {$user->id}: " . $e->getMessage());
            }
        }

        $this->info("Notificaciones de comida enviadas: {$sent} de {$schedules->count()} a las {$now}.");
    }
}

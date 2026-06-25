<?php

namespace App\Console\Commands;

use App\Models\AttendanceDay;
use App\Models\MealSchedule;
use App\Models\User;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendMealNotifications extends Command
{
    protected $signature = 'meals:notify';

    protected $description = 'Envia notificaciones de comida: pre-aviso, inicio y 5 minutos antes de terminar';

    private const PRE_MINUTES = 5;

    private const END_MINUTES = 5;

    public function handle(): void
    {
        $now = now();
        $today = $now->toDateString();
        $time = $now->format('H:i');
        $timeWithSeconds = $now->format('H:i:s');

        $notifier = app(NotificationService::class);

        $preTime = $now->copy()->addMinutes(self::PRE_MINUTES)->format('H:i');
        $endTime = $now->copy()->addMinutes(self::END_MINUTES)->format('H:i');

        $preSent = $this->sendPreReminders($preTime, $today, $notifier);
        $startSent = $this->sendStartReminders($time, $today, $notifier);
        $endSent = $this->sendEndReminders($endTime, $today, $notifier);

        $this->info("Pre-avisos: {$preSent} | Inicios: {$startSent} | Fin proximo: {$endSent} a las {$time}.");
    }

    /**
     * Notifica 5 minutos antes de la hora de comida.
     */
    private function sendPreReminders(string $preTime, string $today, NotificationService $notifier): int
    {
        $schedules = MealSchedule::where('meal_start_time', 'LIKE', $preTime.'%')
            ->with('employee')
            ->get();

        $sent = 0;
        foreach ($schedules as $schedule) {
            $user = $this->resolveUser($schedule);
            if (! $user) {
                continue;
            }

            $day = $this->getOrCreateDay($schedule, $today);
            if ($day->lunch_pre_reminder_sent) {
                continue;
            }

            try {
                $notifier->sendToUser(
                    userId: $user->id,
                    title: '🍽️ Comida pronto',
                    body: "Tu tiempo de comida comienza en 5 minutos ({$schedule->meal_start_time}). Tienes {$schedule->duration_minutes} minutos.",
                    data: [
                        'type' => 'meal_pre_reminder',
                        'duration_minutes' => (string) $schedule->duration_minutes,
                    ]
                );
                $day->lunch_pre_reminder_sent = true;
                $day->save();
                $sent++;
            } catch (\Throwable $e) {
                Log::warning("Error enviando pre-aviso de comida a usuario {$user->id}: ".$e->getMessage());
            }
        }

        return $sent;
    }

    /**
     * Notifica cuando inicia la hora de comida.
     */
    private function sendStartReminders(string $time, string $today, NotificationService $notifier): int
    {
        $schedules = MealSchedule::where('meal_start_time', 'LIKE', $time.'%')
            ->with('employee')
            ->get();

        $sent = 0;
        foreach ($schedules as $schedule) {
            $user = $this->resolveUser($schedule);
            if (! $user) {
                continue;
            }

            $day = $this->getOrCreateDay($schedule, $today);
            if ($day->lunch_reminder_sent) {
                continue;
            }

            try {
                $notifier->sendToUser(
                    userId: $user->id,
                    title: '🍽️ Hora de comida',
                    body: "Tu horario de comida comienza ahora ({$time}). Tienes {$schedule->duration_minutes} minutos.",
                    data: [
                        'type' => 'meal_reminder',
                        'duration_minutes' => (string) $schedule->duration_minutes,
                    ]
                );
                $day->lunch_reminder_sent = true;
                $day->save();
                $sent++;
            } catch (\Throwable $e) {
                Log::warning("Error enviando recordatorio de comida a usuario {$user->id}: ".$e->getMessage());
            }
        }

        return $sent;
    }

    /**
     * Notifica 5 minutos antes de que termine una comida activa.
     */
    private function sendEndReminders(string $endTime, string $today, NotificationService $notifier): int
    {
        $days = AttendanceDay::where('date', $today)
            ->whereNotNull('lunch_start_at')
            ->whereNull('lunch_end_at')
            ->where('lunch_end_reminder_sent', false)
            ->with('empleado')
            ->get();

        $sent = 0;
        foreach ($days as $day) {
            $emp = $day->empleado;
            if (! $emp || ! $emp->user_id) {
                continue;
            }

            $user = User::find($emp->user_id);
            if (! $user || ! $user->is_active) {
                continue;
            }

            $schedule = MealSchedule::where('employee_id', $emp->id)
                ->where('empresa_id', $day->empresa_id)
                ->first();

            $durationMinutes = $schedule?->duration_minutes ?? 30;
            $limit = Carbon::parse($day->lunch_start_at)->addMinutes($durationMinutes);
            $minutesRemaining = (int) now()->diffInMinutes($limit, false);

            // Solo notificar cuando estamos en la ventana de 5 minutos restantes.
            if ($minutesRemaining > self::END_MINUTES || $minutesRemaining < 0) {
                continue;
            }

            try {
                $notifier->sendToUser(
                    userId: $user->id,
                    title: '⏱️ Comida por terminar',
                    body: "Te quedan {$minutesRemaining} minutos para terminar tu comida.",
                    data: [
                        'type' => 'meal_end_reminder',
                        'minutes_remaining' => (string) $minutesRemaining,
                    ]
                );
                $day->lunch_end_reminder_sent = true;
                $day->save();
                $sent++;
            } catch (\Throwable $e) {
                Log::warning("Error enviando aviso de fin de comida a usuario {$user->id}: ".$e->getMessage());
            }
        }

        return $sent;
    }

    private function resolveUser(MealSchedule $schedule): ?User
    {
        $empleado = $schedule->employee;
        if (! $empleado || ! $empleado->user_id) {
            return null;
        }

        $user = User::find($empleado->user_id);
        if (! $user || ! $user->is_active) {
            return null;
        }

        return $user;
    }

    private function getOrCreateDay(MealSchedule $schedule, string $today): AttendanceDay
    {
        $day = AttendanceDay::where('empresa_id', $schedule->empresa_id)
            ->where('empleado_id', $schedule->employee_id)
            ->where('date', $today)
            ->first();

        if ($day) {
            return $day;
        }

        return AttendanceDay::create([
            'empresa_id' => $schedule->empresa_id,
            'empleado_id' => $schedule->employee_id,
            'date' => $today,
            'status' => 'open',
            'totals' => [],
        ]);
    }
}

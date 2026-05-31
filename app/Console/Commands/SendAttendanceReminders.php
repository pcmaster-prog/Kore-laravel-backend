<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\AttendanceDay;
use App\Models\Empresa;
use App\Services\AttendanceService;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SendAttendanceReminders extends Command
{
    protected $signature   = 'attendance:reminders';
    protected $description = 'Envía recordatorios de 5 min antes de salida y notificación de salida disponible';

    public function handle(): void
    {
        $today = now()->toDateString();
        $now = now();

        $days = AttendanceDay::where('date', $today)
            ->whereNotNull('first_check_in_at')
            ->whereNull('last_check_out_at')
            ->where('status', '!=', 'closed')
            ->with('empleado')
            ->get();

        if ($days->isEmpty()) {
            $this->info('No hay jornadas activas hoy.');
            return;
        }

        $notifier = app(NotificationService::class);
        $exitReminderSent = 0;
        $exitAvailableSent = 0;

        foreach ($days as $day) {
            $emp = $day->empleado;
            if (!$emp || !$emp->user_id) continue;

            $user = \App\Models\User::find($emp->user_id);
            if (!$user || !$user->is_active) continue;

            $expectedExit = AttendanceService::calculateExpectedExitTime($day);
            if (!$expectedExit) continue;

            $diffMinutes = $now->diffInMinutes($expectedExit, false);

            // 5 min antes de salida
            if ($diffMinutes <= 5 && $diffMinutes > 0 && !$day->exit_reminder_sent) {
                try {
                    $notifier->sendToUser(
                        userId: $user->id,
                        title: '⏰ Tu jornada está por terminar',
                        body: "Faltan {$diffMinutes} minutos para completar tu horario.",
                        data: ['type' => 'exit_reminder', 'expected_exit' => $expectedExit->toISOString()]
                    );
                    $day->exit_reminder_sent = true;
                    $day->save();
                    $exitReminderSent++;
                } catch (\Throwable $e) {
                    Log::warning("Error enviando reminder de salida a {$user->id}: " . $e->getMessage());
                }
            }

            // Ya puedes registrar tu salida
            if ($diffMinutes <= 0 && !$day->exit_available_sent) {
                try {
                    $notifier->sendToUser(
                        userId: $user->id,
                        title: '✅ Ya puedes registrar tu salida',
                        body: 'Tu jornada laboral ha terminado. Puedes marcar tu salida ahora.',
                        data: ['type' => 'exit_available', 'expected_exit' => $expectedExit->toISOString()]
                    );
                    $day->exit_available_sent = true;
                    $day->save();
                    $exitAvailableSent++;
                } catch (\Throwable $e) {
                    Log::warning("Error enviando salida disponible a {$user->id}: " . $e->getMessage());
                }
            }
        }

        $this->info("Reminders enviados: {$exitReminderSent} (5 min antes), {$exitAvailableSent} (salida disponible).");
    }
}

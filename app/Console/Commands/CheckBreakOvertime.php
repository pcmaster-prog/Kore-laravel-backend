<?php

namespace App\Console\Commands;

use App\Models\AttendanceDay;
use App\Models\AttendanceEvent;
use App\Models\Empresa;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckBreakOvertime extends Command
{
    protected $signature = 'breaks:check-overtime';

    protected $description = 'Detecta breaks activos que excedan la duración configurada y notifica';

    public function handle(): void
    {
        $today = now()->toDateString();

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
        $notified = 0;

        foreach ($days as $day) {
            // Verificar si hay un break_start sin break_end
            $lastBreak = AttendanceEvent::where('attendance_day_id', $day->id)
                ->whereIn('type', ['break_start', 'break_end'])
                ->orderByDesc('occurred_at')
                ->first();

            if (! $lastBreak || $lastBreak->type !== 'break_start') {
                continue;
            }

            $emp = $day->empleado;
            if (! $emp || ! $emp->user_id) {
                continue;
            }

            $user = User::find($emp->user_id);
            if (! $user || ! $user->is_active) {
                continue;
            }

            $empresa = Empresa::find($day->empresa_id);
            $settings = is_array($empresa?->settings) ? $empresa->settings : [];
            $breakDuration = (int) ($settings['operativo']['break_duration_minutes'] ?? 10);

            $breakMinutes = (int) now()->diffInMinutes($lastBreak->occurred_at);

            if ($breakMinutes > $breakDuration) {
                $exceso = $breakMinutes - $breakDuration;
                try {
                    $notifier->sendToUser(
                        userId: $user->id,
                        title: '⚠️ Tu descanso ha excedido el tiempo',
                        body: "Llevas {$breakMinutes} min de descanso (límite: {$breakDuration} min). Exceso: {$exceso} min.",
                        data: [
                            'type' => 'break_overtime',
                            'exceso_min' => (string) $exceso,
                            'break_minutes' => (string) $breakMinutes,
                        ]
                    );
                    $notified++;
                } catch (\Throwable $e) {
                    Log::warning("Error notificando exceso de break a {$user->id}: ".$e->getMessage());
                }
            }
        }

        $this->info("Notificaciones de exceso de break enviadas: {$notified}.");
    }
}

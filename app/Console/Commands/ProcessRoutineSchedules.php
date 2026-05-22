<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\RoutineSchedule;
use App\Models\TaskRoutineItem;
use App\Models\Task;
use App\Models\TaskAssignee;
use App\Models\Empleado;
use App\Jobs\SendPushNotification;
use Illuminate\Support\Facades\Log;

class ProcessRoutineSchedules extends Command
{
    protected $signature = 'tasks:process-routine-schedules';
    protected $description = 'Procesa rutinas automáticas configuradas por horario';

    public function handle(): int
    {
        $now = now();
        $todayDow = $now->dayOfWeek;
        $windowStart = $now->copy()->subMinutes(5)->format('H:i:s');
        $windowEnd = $now->copy()->addMinutes(5)->format('H:i:s');

        $this->info("Procesando rutinas automáticas para {$now->toDateTimeString()}...");

        $schedules = RoutineSchedule::where('is_active', true)
            ->where('auto_assign', true)
            ->whereJsonContains('trigger_days', $todayDow)
            ->whereTime('trigger_time', '>=', $windowStart)
            ->whereTime('trigger_time', '<=', $windowEnd)
            ->with(['routine' => function ($q) {
                $q->where('is_active', true);
            }, 'routine.items' => function ($q) {
                $q->where('is_active', true);
            }])
            ->cursor();

        $createdCount = 0;

        foreach ($schedules as $schedule) {
            $routine = $schedule->routine;
            if (!$routine) continue;

            $items = TaskRoutineItem::where('routine_id', $routine->id)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get();

            if ($items->isEmpty()) continue;

            $empresaId = $schedule->empresa_id;
            $empleadoIds = []; // Aquí se definirían los empleados objetivo (por ahora vacío hasta definir lógica)

            // Por defecto, si la rutina no tiene empleados específicos, no se asigna automáticamente
            // (la lógica de asignación de rutinas puede extenderse según necesidad del negocio)
            foreach ($items as $item) {
                $template = $item->taskTemplate;
                if (!$template || !$template->is_active) continue;

                // Aquí iría la lógica de asignación masiva si se define
                // Por ahora solo loggeamos
                Log::info("RoutineSchedule triggered", [
                    'schedule_id' => $schedule->id,
                    'routine_id' => $routine->id,
                    'template_id' => $template->id,
                    'template_title' => $template->title,
                ]);
            }
        }

        $this->info("Completado: {$createdCount} tareas creadas desde rutinas.");
        return self::SUCCESS;
    }
}

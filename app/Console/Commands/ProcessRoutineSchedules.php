<?php

namespace App\Console\Commands;

use App\Jobs\SendPushNotification;
use App\Models\Empleado;
use App\Models\RoutineSchedule;
use App\Models\Task;
use App\Models\TaskAssignee;
use App\Models\TaskRoutineItem;
use Illuminate\Console\Command;

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

        // Buscar schedules activos que apliquen hoy y estén en la ventana de tiempo
        $schedules = RoutineSchedule::where('is_active', true)
            ->where('auto_assign', true)
            ->whereJsonContains('trigger_days', $todayDow)
            ->whereTime('trigger_time', '>=', $windowStart)
            ->whereTime('trigger_time', '<=', $windowEnd)
            ->with(['routine' => fn ($q) => $q->where('is_active', true)])
            ->cursor();

        $createdCount = 0;

        foreach ($schedules as $schedule) {
            $routine = $schedule->routine;
            if (! $routine) {
                continue;
            }

            $items = TaskRoutineItem::where('routine_id', $routine->id)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->with('taskTemplate')
                ->get();

            if ($items->isEmpty()) {
                continue;
            }

            $empleadoIds = $this->resolveRoutineAssignees($schedule);

            if (empty($empleadoIds)) {
                // Notificar a supervisores de la rutina/empresa
                $this->warn("Rutina {$routine->name} sin asignados");

                continue;
            }

            foreach ($empleadoIds as $empleadoId) {
                foreach ($items as $item) {
                    $template = $item->taskTemplate;
                    if (! $template || ! $template->is_active) {
                        continue;
                    }

                    // Deduplicación: template_id + empleado_id + fecha
                    $exists = Task::where('empresa_id', $schedule->empresa_id)
                        ->whereHas('assignees', fn ($q) => $q->where('empleado_id', $empleadoId))
                        ->whereRaw("meta->>'template_id' = ?", [$template->id])
                        ->whereRaw("meta->>'catalog_date' = ?", [$now->toDateString()])
                        ->whereRaw("meta->>'source' = ?", ['auto_routine'])
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    $task = Task::create([
                        'empresa_id' => $schedule->empresa_id,
                        'created_by' => $schedule->created_by,
                        'title' => $template->title,
                        'description' => $template->description,
                        'priority' => $template->priority ?? 'medium',
                        'status' => 'open',
                        'area_id' => $template->area_id,
                        'section_id' => $template->section_id,
                        'meta' => [
                            'template_id' => $template->id,
                            'catalog_date' => $now->toDateString(),
                            'source' => 'auto_routine',
                            'routine_id' => $routine->id,
                            'routine_schedule_id' => $schedule->id,
                        ],
                    ]);

                    TaskAssignee::create([
                        'empresa_id' => $schedule->empresa_id,
                        'task_id' => $task->id,
                        'empleado_id' => $empleadoId,
                        'status' => 'assigned',
                    ]);

                    $createdCount++;

                    if ($schedule->notify_push) {
                        $emp = Empleado::where('id', $empleadoId)->first();
                        if ($emp && $emp->user_id) {
                            SendPushNotification::dispatch(
                                $emp->user_id,
                                '📋 Rutina asignada: '.$routine->name,
                                "Se te asignó: {$task->title}",
                                ['type' => 'routine.assigned', 'task_id' => $task->id, 'routine_id' => $routine->id]
                            );
                        }
                    }
                }
            }
        }

        $this->info("Completado: {$createdCount} tareas creadas desde rutinas.");

        return self::SUCCESS;
    }

    private function resolveRoutineAssignees(RoutineSchedule $schedule): array
    {
        if ($schedule->assignee_type === 'empleado') {
            if (! $schedule->assignee_id) {
                return [];
            }
            $emp = Empleado::where('id', $schedule->assignee_id)->where('status', 'active')->first();

            return $emp ? [$emp->id] : [];
        }

        if ($schedule->assignee_type === 'position') {
            if (! $schedule->assignee_id) {
                return [];
            }

            return Empleado::where('position_id', $schedule->assignee_id)
                ->where('status', 'active')
                ->pluck('id')
                ->all();
        }

        if ($schedule->assignee_type === 'section') {
            if (! $schedule->section_id) {
                return [];
            }

            return Empleado::where('status', 'active')
                ->whereHas('sections', fn ($q) => $q->where('section_id', $schedule->section_id))
                ->pluck('id')
                ->all();
        }

        if ($schedule->assignee_type === 'area') {
            if (! $schedule->area_id) {
                return [];
            }

            return Empleado::where('status', 'active')
                ->whereHas('sections', fn ($q) => $q->whereHas('area', fn ($a) => $a->where('id', $schedule->area_id)))
                ->pluck('id')
                ->all();
        }

        return [];
    }
}

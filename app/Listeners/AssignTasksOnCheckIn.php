<?php

namespace App\Listeners;

use App\Events\AttendanceCheckedIn;
use App\Models\TaskAssignmentRule;
use App\Models\TaskTemplate;
use App\Models\Task;
use App\Models\TaskAssignee;
use App\Models\Empleado;
use App\Jobs\SendPushNotification;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\Log;

class AssignTasksOnCheckIn
{
    public function handle(AttendanceCheckedIn $event): void
    {
        $day = $event->day;
        $empresaId = $day->empresa_id;
        $empleadoId = $day->empleado_id;
        $todayDow = now()->dayOfWeek;

        // Buscar reglas que requieren check-in para este empleado hoy
        $rules = TaskAssignmentRule::where('empresa_id', $empresaId)
            ->where('is_active', true)
            ->whereJsonContains('day_of_week', $todayDow)
            ->whereIn('trigger_event', ['attendance_checkin', 'both'])
            ->where(function ($q) use ($empleadoId) {
                $q->where(function ($q2) use ($empleadoId) {
                    $q2->where('assignee_type', 'empleado')
                       ->where('assignee_id', $empleadoId);
                })
                ->orWhere(function ($q2) use ($empleadoId) {
                    $q2->where('assignee_type', 'position')
                       ->whereIn('assignee_id', function ($sub) use ($empleadoId) {
                           $sub->select('position_id')
                               ->from('empleados')
                               ->where('id', $empleadoId)
                               ->whereNotNull('position_id');
                       });
                })
                ->orWhere(function ($q2) use ($empleadoId) {
                    $q2->where('assignee_type', 'section_supervisor')
                       ->whereIn('section_id', function ($sub) use ($empleadoId) {
                           $sub->select('section_id')
                               ->from('empleado_sections')
                               ->where('empleado_id', $empleadoId);
                       });
                });
            })
            ->with(['items' => fn($q) => $q->where('is_active', true)->orderBy('sort_order')])
            ->get();

        if ($rules->isEmpty()) {
            return;
        }

        $empleado = Empleado::where('id', $empleadoId)->first();
        $createdTasks = [];

        foreach ($rules as $rule) {
            // Obtener todos los templates de la regla
            $templateIds = [];

            if ($rule->task_template_id) {
                $templateIds[] = $rule->task_template_id;
            }

            $itemTemplateIds = $rule->items->pluck('template_id')->all();
            $templateIds = array_values(array_unique(array_merge($templateIds, $itemTemplateIds)));

            foreach ($templateIds as $templateId) {
                $template = TaskTemplate::where('id', $templateId)->where('is_active', true)->first();
                if (!$template) continue;

                // Verificar que no exista ya una tarea hoy para este template+empleado
                $alreadyExists = Task::where('empresa_id', $empresaId)
                    ->whereHas('assignees', function ($q) use ($empleadoId) {
                        $q->where('empleado_id', $empleadoId);
                    })
                    ->whereRaw("meta->>'template_id' = ?", [$templateId])
                    ->whereRaw("meta->>'catalog_date' = ?", [now()->toDateString()])
                    ->exists();

                if ($alreadyExists) continue;

                $task = Task::create([
                    'empresa_id' => $empresaId,
                    'created_by' => $rule->created_by,
                    'title' => $template->title,
                    'description' => $template->description,
                    'priority' => $template->priority ?? 'medium',
                    'status' => 'open',
                    'area_id' => $template->area_id,
                    'section_id' => $template->section_id,
                    'meta' => [
                        'template_id' => $template->id,
                        'catalog_date' => now()->toDateString(),
                        'source' => 'auto_rule',
                        'trigger_event' => 'attendance_checkin',
                        'resolved_by' => 'checkin',
                    ],
                ]);

                TaskAssignee::create([
                    'empresa_id' => $empresaId,
                    'task_id' => $task->id,
                    'empleado_id' => $empleadoId,
                    'status' => 'assigned',
                    'meta' => null,
                ]);

                $createdTasks[] = $task;

                // Notificar al empleado
                if ($empleado && $empleado->user_id) {
                    SendPushNotification::dispatch(
                        $empleado->user_id,
                        '📋 Nueva tarea asignada',
                        "Se te asignó: {$task->title}",
                        ['type' => 'task.assigned', 'task_id' => $task->id]
                    );
                }
            }
        }

        if (!empty($createdTasks) && $empleado && $empleado->user_id) {
            ActivityLogger::log(
                $empresaId,
                null,
                $empleadoId,
                'task.auto_assigned_on_checkin',
                'task',
                $createdTasks[0]->id,
                ['count' => count($createdTasks)],
                null
            );
        }
    }
}

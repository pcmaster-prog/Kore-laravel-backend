<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\TaskAssignmentRule;
use App\Models\Task;
use App\Models\TaskAssignee;
use App\Models\Empleado;
use App\Models\Position;
use App\Jobs\SendPushNotification;
use App\Jobs\SendPushNotificationToManagers;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\Log;

class ProcessTaskAssignmentRules extends Command
{
    protected $signature = 'tasks:process-assignment-rules';
    protected $description = 'Procesa reglas de asignación automática de tareas por horario';

    public function handle(): int
    {
        $now = now();
        $todayDow = $now->dayOfWeek;
        $currentTime = $now->format('H:i');
        $windowStart = $now->copy()->subMinutes(5)->format('H:i:s');
        $windowEnd = $now->copy()->addMinutes(5)->format('H:i:s');

        $this->info("Procesando reglas de asignación para {$now->toDateTimeString()}...");

        $rules = TaskAssignmentRule::where('is_active', true)
            ->whereJsonContains('day_of_week', $todayDow)
            ->whereIn('trigger_event', ['time', 'both'])
            ->whereNotNull('trigger_time')
            ->whereTime('trigger_time', '>=', $windowStart)
            ->whereTime('trigger_time', '<=', $windowEnd)
            ->with('taskTemplate')
            ->cursor();

        $createdCount = 0;
        $skippedCount = 0;
        $missingAssigneeCount = 0;

        foreach ($rules as $rule) {
            $template = $rule->taskTemplate;
            if (!$template || !$template->is_active) continue;

            $empresaId = $rule->empresa_id;
            $empleadoIds = $this->resolveAssignees($rule);

            if (empty($empleadoIds)) {
                // Crear tarea sin asignado para trazabilidad
                $alreadyExistsUnassigned = Task::where('empresa_id', $empresaId)
                    ->whereRaw("meta->>'template_id' = ?", [$template->id])
                    ->whereRaw("meta->>'catalog_date' = ?", [$now->toDateString()])
                    ->whereRaw("meta->>'unassigned_reason' = ?", ['missing_assignee'])
                    ->exists();

                if (!$alreadyExistsUnassigned) {
                    Task::create([
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
                            'catalog_date' => $now->toDateString(),
                            'source' => 'auto_rule',
                            'trigger_event' => $rule->trigger_event,
                            'resolved_by' => 'cron',
                            'unassigned_reason' => 'missing_assignee',
                            'rule_id' => $rule->id,
                        ],
                    ]);
                }

                // No hay asignado disponible: notificar a supervisores
                $this->createMissingAssigneeIncident($rule, $template, $empresaId);
                $missingAssigneeCount++;
                continue;
            }

            foreach ($empleadoIds as $empleadoId) {
                // Verificar que no exista ya una tarea hoy
                $alreadyExists = Task::where('empresa_id', $empresaId)
                    ->whereHas('assignees', function ($q) use ($empleadoId) {
                        $q->where('empleado_id', $empleadoId);
                    })
                    ->whereRaw("meta->>'template_id' = ?", [$template->id])
                    ->whereRaw("meta->>'catalog_date' = ?", [$now->toDateString()])
                    ->exists();

                if ($alreadyExists) {
                    $skippedCount++;
                    continue;
                }

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
                        'catalog_date' => $now->toDateString(),
                        'source' => 'auto_rule',
                        'trigger_event' => $rule->trigger_event,
                        'resolved_by' => 'cron',
                    ],
                ]);

                TaskAssignee::create([
                    'empresa_id' => $empresaId,
                    'task_id' => $task->id,
                    'empleado_id' => $empleadoId,
                    'status' => 'assigned',
                    'meta' => null,
                ]);

                $createdCount++;

                // Notificar al empleado
                $empleado = Empleado::where('id', $empleadoId)->first();
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

        $this->info("Completado: {$createdCount} tareas creadas, {$skippedCount} omitidas, {$missingAssigneeCount} sin asignado.");
        Log::info("ProcessTaskAssignmentRules: {$createdCount} creadas, {$skippedCount} omitidas, {$missingAssigneeCount} sin asignado.");

        return self::SUCCESS;
    }

    private function resolveAssignees(TaskAssignmentRule $rule): array
    {
        if ($rule->assignee_type === 'empleado') {
            if (!$rule->assignee_id) return [];
            // Verificar que el empleado esté activo
            $emp = Empleado::where('id', $rule->assignee_id)->where('status', 'active')->first();
            return $emp ? [$emp->id] : [];
        }

        if ($rule->assignee_type === 'position') {
            if (!$rule->assignee_id) return [];
            return Empleado::where('position_id', $rule->assignee_id)
                ->where('status', 'active')
                ->pluck('id')
                ->all();
        }

        if ($rule->assignee_type === 'section_supervisor') {
            if (!$rule->section_id) return [];
            return Empleado::where('status', 'active')
                ->whereHas('sections', fn($q) => $q->where('section_id', $rule->section_id))
                ->pluck('id')
                ->all();
        }

        return [];
    }

    private function createMissingAssigneeIncident(TaskAssignmentRule $rule, $template, string $empresaId): void
    {
        if (!$rule->section_id) return;

        // Buscar supervisores de la sección
        $supervisors = \App\Models\SupervisorSection::where('section_id', $rule->section_id)
            ->pluck('supervisor_user_id')
            ->all();

        if (empty($supervisors)) return;

        // Notificar a supervisores
        foreach ($supervisors as $supervisorId) {
            SendPushNotification::dispatch(
                $supervisorId,
                '⚠️ Tarea sin asignado',
                "La tarea '{$template->title}' no tiene empleado disponible para hoy.",
                ['type' => 'task.missing_assignee', 'template_id' => $template->id]
            );
        }
    }
}

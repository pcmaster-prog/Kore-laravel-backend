<?php

namespace App\Console\Commands;

use App\Jobs\SendPushNotification;
use App\Models\Empleado;
use App\Models\SupervisorSection;
use App\Models\Task;
use App\Models\TaskAssignee;
use App\Models\TaskAssignmentRule;
use App\Models\TaskTemplate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessTaskAssignmentRules extends Command
{
    protected $signature = 'tasks:process-assignment-rules';

    protected $description = 'Procesa reglas de asignación automática de tareas por horario';

    public function handle(): int
    {
        $now = now();
        $todayDow = $now->dayOfWeek;
        $windowStart = $now->copy()->subMinutes(5)->format('H:i:s');
        $windowEnd = $now->copy()->addMinutes(5)->format('H:i:s');

        $this->info("Procesando reglas de asignación para {$now->toDateTimeString()}...");

        $rules = TaskAssignmentRule::where('is_active', true)
            ->whereJsonContains('day_of_week', $todayDow)
            ->whereIn('trigger_event', ['time', 'both'])
            ->whereNotNull('trigger_time')
            ->whereTime('trigger_time', '>=', $windowStart)
            ->whereTime('trigger_time', '<=', $windowEnd)
            ->with(['items' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')])
            ->cursor();

        $createdCount = 0;
        $skippedCount = 0;
        $missingAssigneeCount = 0;

        foreach ($rules as $rule) {
            $empresaId = $rule->empresa_id;
            $empleadoIds = $this->resolveAssignees($rule);

            // Obtener todos los templates de la regla
            $templateIds = [];

            if ($rule->task_template_id) {
                $templateIds[] = $rule->task_template_id;
            }

            $itemTemplateIds = $rule->items->pluck('template_id')->all();
            $templateIds = array_values(array_unique(array_merge($templateIds, $itemTemplateIds)));

            if (empty($templateIds)) {
                continue;
            }

            if (empty($empleadoIds)) {
                // Crear tareas huérfanas para cada template
                foreach ($templateIds as $templateId) {
                    $template = TaskTemplate::where('id', $templateId)->where('is_active', true)->first();
                    if (! $template) {
                        continue;
                    }

                    $alreadyExists = Task::where('empresa_id', $empresaId)
                        ->whereRaw("meta->>'template_id' = ?", [$templateId])
                        ->whereRaw("meta->>'catalog_date' = ?", [$now->toDateString()])
                        ->whereRaw("meta->>'unassigned_reason' = ?", ['missing_assignee'])
                        ->exists();

                    if (! $alreadyExists) {
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

                    $this->createMissingAssigneeIncident($rule, $template, $empresaId);
                }
                $missingAssigneeCount++;

                continue;
            }

            foreach ($empleadoIds as $empleadoId) {
                foreach ($templateIds as $templateId) {
                    $template = TaskTemplate::where('id', $templateId)->where('is_active', true)->first();
                    if (! $template) {
                        continue;
                    }

                    // Deduplicación por template+empleado+fecha
                    $alreadyExists = Task::where('empresa_id', $empresaId)
                        ->whereHas('assignees', function ($q) use ($empleadoId) {
                            $q->where('empleado_id', $empleadoId);
                        })
                        ->whereRaw("meta->>'template_id' = ?", [$templateId])
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
        }

        $this->info("Completado: {$createdCount} tareas creadas, {$skippedCount} omitidas, {$missingAssigneeCount} sin asignado.");
        Log::info("ProcessTaskAssignmentRules: {$createdCount} creadas, {$skippedCount} omitidas, {$missingAssigneeCount} sin asignado.");

        return self::SUCCESS;
    }

    private function resolveAssignees(TaskAssignmentRule $rule): array
    {
        if ($rule->assignee_type === 'empleado') {
            if (! $rule->assignee_id) {
                return [];
            }
            $emp = Empleado::where('id', $rule->assignee_id)->where('status', 'active')->first();

            return $emp ? [$emp->id] : [];
        }

        if ($rule->assignee_type === 'position') {
            if (! $rule->assignee_id) {
                return [];
            }

            return Empleado::where('position_id', $rule->assignee_id)
                ->where('status', 'active')
                ->pluck('id')
                ->all();
        }

        if ($rule->assignee_type === 'section_supervisor') {
            if (! $rule->section_id) {
                return [];
            }

            return Empleado::where('status', 'active')
                ->whereHas('sections', fn ($q) => $q->where('section_id', $rule->section_id))
                ->pluck('id')
                ->all();
        }

        return [];
    }

    private function createMissingAssigneeIncident(TaskAssignmentRule $rule, $template, string $empresaId): void
    {
        if (! $rule->section_id) {
            return;
        }

        $supervisors = SupervisorSection::where('section_id', $rule->section_id)
            ->pluck('supervisor_user_id')
            ->all();

        if (empty($supervisors)) {
            return;
        }

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

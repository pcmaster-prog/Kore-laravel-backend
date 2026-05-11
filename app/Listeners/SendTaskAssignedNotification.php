<?php

namespace App\Listeners;

use App\Events\TaskAssigned;
use App\Jobs\SendPushNotification;
use Illuminate\Support\Facades\Log;

class SendTaskAssignedNotification
{
    /**
     * Handle the event.
     */
    public function handle(TaskAssigned $event): void
    {
        $task = $event->task;

        // Cargar assignees con sus empleados para evitar N+1
        $assignees = $task->assignees()->with('empleado.user')->get();

        foreach ($assignees as $assignee) {
            $empleado = $assignee->empleado;

            if (! $empleado) {
                continue;
            }

            $user = $empleado->user;

            if (! $user) {
                continue;
            }

            try {
                SendPushNotification::dispatch(
                    $user->id,
                    'Nueva tarea asignada',
                    $task->title,
                    [
                        'type'    => 'task_assigned',
                        'task_id' => $task->id,
                    ]
                );
            } catch (\Throwable $e) {
                Log::error('SendTaskAssignedNotification listener failed', [
                    'task_id' => $task->id,
                    'user_id' => $user->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }
    }
}

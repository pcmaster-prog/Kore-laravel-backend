<?php

namespace App\Services;

use App\Models\Empleado;
use App\Models\Task;
use App\Models\TaskAssignee;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TaskService
{
    /**
     * Valida que un supervisor solo asigne tareas de su propia sección.
     * Lanza HttpException 403 si no coincide.
     */
    public static function requireSupervisorSection(User $authUser, ?string $templateSection): void
    {
        if ($authUser->role === 'supervisor' && $authUser->section) {
            if ($templateSection !== $authUser->section) {
                abort(403, "No puedes asignar tareas de otra sección. Tu sección asignada es: {$authUser->section}");
            }
        }
    }

    /**
     * Valida si un usuario puede asignar una tarea a un empleado según jerarquía.
     */
    public static function canAssignTo(User $authUser, Empleado $empleado): bool
    {
        $targetRole = $empleado->user?->role ?? 'empleado';

        if ($empleado->user_id === $authUser->id) return false;
        if ($authUser->role === 'supervisor' && in_array($targetRole, ['supervisor', 'admin'])) return false;
        if ($authUser->role === 'admin' && $targetRole === 'admin') return false;

        return true;
    }

    /**
     * Ejecuta la asignación de una tarea a uno o más empleados.
     * Retorna un array con 'success' => true/false, 'message', 'code' y 'assignees'.
     */
    public static function assignTask(Task $task, array $empleadoIds, User $authUser): array
    {
        $empleados = Empleado::where('empresa_id', $authUser->empresa_id)
            ->whereIn('id', $empleadoIds)
            ->with('user')
            ->get();

        if ($empleados->count() !== count($empleadoIds)) {
            return ['success' => false, 'message' => 'Uno o más empleados no pertenecen a esta empresa', 'code' => 422];
        }

        foreach ($empleados as $emp) {
            if (!self::canAssignTo($authUser, $emp)) {
                $targetRole = $emp->user?->role ?? 'empleado';

                if ($emp->user_id === $authUser->id) {
                    return ['success' => false, 'message' => 'No puedes asignarte una tarea a ti mismo', 'code' => 422];
                }

                if ($authUser->role === 'supervisor' && in_array($targetRole, ['supervisor', 'admin'])) {
                    return ['success' => false, 'message' => "Un supervisor solo puede asignar tareas a empleados. {$emp->full_name} es {$targetRole}.", 'code' => 422];
                }

                if ($authUser->role === 'admin' && $targetRole === 'admin') {
                    return ['success' => false, 'message' => 'No puedes asignar tareas a otros administradores.', 'code' => 422];
                }
            }
        }

        $assignees = [];
        DB::transaction(function () use ($authUser, $task, $empleados, &$assignees) {
            foreach ($empleados as $emp) {
                $assignee = TaskAssignee::firstOrCreate(
                    ['empresa_id' => $authUser->empresa_id, 'task_id' => $task->id, 'empleado_id' => $emp->id],
                    ['status' => 'assigned']
                );
                $assignees[] = ['assignee' => $assignee, 'empleado' => $emp];
            }
        });

        return ['success' => true, 'assignees' => $assignees];
    }
}

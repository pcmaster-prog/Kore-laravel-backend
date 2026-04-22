<?php

namespace App\Observers;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Generic audit observer for sensitive models.
 * Records create, update, and delete operations to the audit_logs table.
 *
 * Register in AppServiceProvider::boot() for each model that needs auditing:
 *   User::observe(AuditObserver::class);
 *   Empleado::observe(AuditObserver::class);
 *   PayrollPeriod::observe(AuditObserver::class);
 */
class AuditObserver
{
    public function created(Model $model): void
    {
        $this->logAction($model, 'created');
    }

    public function updated(Model $model): void
    {
        // Solo registrar si realmente cambió algo
        if (empty($model->getChanges())) {
            return;
        }

        $this->logAction($model, 'updated', [
            'changed_fields' => array_keys($model->getChanges()),
            'old_values'     => collect($model->getOriginal())
                ->only(array_keys($model->getChanges()))
                ->toArray(),
            'new_values'     => $model->getChanges(),
        ]);
    }

    public function deleted(Model $model): void
    {
        $this->logAction($model, 'deleted');
    }

    private function logAction(Model $model, string $action, array $extraMeta = []): void
    {
        $userId   = auth()->id();
        $empresaId = $model->empresa_id ?? $model->getAttribute('empresa_id') ?? null;

        // No registrar si no hay usuario autenticado (ej. seeders, commands)
        if (!$userId) {
            return;
        }

        try {
            AuditLog::create([
                'empresa_id'    => $empresaId,
                'actor_user_id' => $userId,
                'action'        => class_basename($model) . '.' . $action,
                'entity_type'   => get_class($model),
                'entity_id'     => $model->getKey(),
                'meta'          => array_merge([
                    'model_data' => $action === 'deleted'
                        ? $model->toArray()
                        : null,
                ], $extraMeta),
            ]);
        } catch (\Throwable $e) {
            // Nunca dejar que un fallo de auditoría rompa la operación principal
            \Illuminate\Support\Facades\Log::error('AuditObserver error: ' . $e->getMessage());
        }
    }
}

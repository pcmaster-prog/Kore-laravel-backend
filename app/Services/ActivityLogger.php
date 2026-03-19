<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Http\Request;

class ActivityLogger
{
    public static function log(
        string $empresaId,
        ?string $userId,
        ?string $empleadoId,
        string $action,
        ?string $entityType = null,
        ?string $entityId = null,
        ?array $meta = null,
        ?Request $request = null
    ): ActivityLog {
        return ActivityLog::create([
            'empresa_id' => $empresaId,
            'user_id' => $userId,
            'empleado_id' => $empleadoId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'meta' => $meta,
            'ip' => $request?->ip(),
            'user_agent' => $request ? substr((string)$request->userAgent(), 0, 250) : null,
        ]);
    }
}

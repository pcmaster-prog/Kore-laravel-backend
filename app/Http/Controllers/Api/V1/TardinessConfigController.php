<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TardinessConfig;
use Illuminate\Http\Request;

class TardinessConfigController extends Controller
{
    /**
     * GET /api/v1/config/retardos
     * Lee la configuración de retardos de la empresa del usuario autenticado.
     * Si no existe, crea una con defaults (lazy initialization).
     */
    public function show(Request $request)
    {
        $u = $request->user();

        $config = TardinessConfig::forEmpresa($u->empresa_id);

        return response()->json($config);
    }

    /**
     * PATCH /api/v1/config/retardos
     * Actualiza la configuración de retardos.
     * Todos los campos son opcionales.
     */
    public function update(Request $request)
    {
        $u = $request->user();

        $data = $request->validate([
            'grace_period_minutes' => 'integer|min:0|max:60',
            'late_threshold_minutes' => 'integer|min:1|max:480',
            'lates_to_absence' => 'integer|min:1|max:10',
            'accumulation_period' => 'in:week,biweek,month',
            'penalize_rest_day' => 'boolean',
            'notify_employee_on_late' => 'boolean',
            'notify_manager_on_late' => 'boolean',
        ]);

        // Ensure config exists (lazy init)
        $config = TardinessConfig::forEmpresa($u->empresa_id);

        $config->update($data);

        return response()->json([
            'message' => 'Configuración actualizada',
            'config' => $config->fresh(),
        ]);
    }
}

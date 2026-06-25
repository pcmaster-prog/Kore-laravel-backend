<?php

// ActivityLogsController: manejo de logs de actividad para auditoría, con filtros por acción, entidad y rango de fechas

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ActivityLogsController extends Controller
{
    public function index(Request $request)
    {
        $u = $request->user();
        Gate::authorize('supervisor');

        $q = ActivityLog::where('empresa_id', $u->empresa_id);

        if ($request->filled('action')) {
            $q->where('action', $request->string('action'));
        }

        if ($request->filled('entity_type')) {
            $q->where('entity_type', $request->string('entity_type'));
        }

        if ($request->filled('entity_id')) {
            $q->where('entity_id', $request->string('entity_id'));
        }

        if ($request->filled('from')) {
            $q->where('created_at', '>=', $request->string('from'));
        }

        if ($request->filled('to')) {
            $q->where('created_at', '<=', $request->string('to'));
        }

        return response()->json($q->orderByDesc('created_at')->paginate(30));
    }
}

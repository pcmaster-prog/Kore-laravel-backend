<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MealSchedule;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MealScheduleController extends Controller
{
    /**
     * GET /meal-schedules
     * Retorna la lista de horarios de comida configurados para la empresa.
     */
    public function index(Request $request): JsonResponse
    {
        $u = $request->user();

        if (!in_array($u->role, ['admin', 'supervisor'])) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $schedules = MealSchedule::where('empresa_id', $u->empresa_id)
            ->with('employee:id,name')
            ->get()
            ->map(fn($s) => [
                'id'                => $s->id,
                'employee_id'       => $s->employee_id,
                'employee_name'     => $s->employee?->name ?? '—',
                'meal_start_time'   => substr($s->meal_start_time, 0, 5), // HH:mm
                'duration_minutes'  => $s->duration_minutes,
            ]);

        return response()->json(['data' => $schedules]);
    }

    /**
     * POST /meal-schedules/bulk
     * Recibe un array de horarios y sincroniza la tabla meal_schedules.
     * Usa updateOrCreate para cada entrada.
     */
    public function bulkStore(Request $request): JsonResponse
    {
        $u = $request->user();

        if ($u->role !== 'admin') {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $data = $request->validate([
            'schedules'                      => ['required', 'array', 'min:1'],
            'schedules.*.employee_id'        => ['required', 'integer', 'exists:users,id'],
            'schedules.*.meal_start_time'    => ['required', 'date_format:H:i'],
            'schedules.*.duration_minutes'   => ['sometimes', 'integer', 'min:5', 'max:120'],
        ]);

        $empresaId = $u->empresa_id;
        $results = [];

        foreach ($data['schedules'] as $item) {
            // Verificar que el empleado pertenece a la misma empresa
            $employee = User::where('id', $item['employee_id'])
                ->where('empresa_id', $empresaId)
                ->first();

            if (!$employee) {
                continue; // Ignorar empleados que no pertenecen a la empresa
            }

            $schedule = MealSchedule::updateOrCreate(
                [
                    'employee_id' => $item['employee_id'],
                    'empresa_id'  => $empresaId,
                ],
                [
                    'meal_start_time'  => $item['meal_start_time'] . ':00', // Append seconds
                    'duration_minutes' => $item['duration_minutes'] ?? 30,
                ]
            );

            $results[] = [
                'id'                => $schedule->id,
                'employee_id'       => $schedule->employee_id,
                'employee_name'     => $employee->name,
                'meal_start_time'   => substr($schedule->meal_start_time, 0, 5),
                'duration_minutes'  => $schedule->duration_minutes,
            ];
        }

        return response()->json([
            'message' => count($results) . ' horario(s) sincronizado(s)',
            'data'    => $results,
        ]);
    }
}

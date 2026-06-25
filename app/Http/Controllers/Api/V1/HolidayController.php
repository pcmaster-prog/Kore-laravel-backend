<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Holiday;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class HolidayController extends Controller
{
    /**
     * GET /api/v1/empresa/festivos
     * Lista todos los festivos de la empresa del usuario autenticado.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $holidays = Holiday::where('empresa_id', $user->empresa_id)
            ->orderBy('date', 'asc')
            ->get();

        return response()->json([
            'data' => $holidays,
        ]);
    }

    /**
     * POST /api/v1/empresa/festivos
     * Crea o actualiza un festivo manualmente.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->role !== 'admin') {
            return response()->json(['message' => 'Solo el administrador puede gestionar festivos'], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'date' => ['required', 'date_format:Y-m-d'],
            'is_paid' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Datos inválidos', 'errors' => $validator->errors()], 422);
        }

        $holiday = Holiday::updateOrCreate(
            [
                'empresa_id' => $user->empresa_id,
                'date' => $request->input('date'),
            ],
            [
                'name' => $request->input('name'),
                'is_paid' => $request->boolean('is_paid', true),
            ]
        );

        return response()->json([
            'message' => $holiday->wasRecentlyCreated ? 'Festivo creado' : 'Festivo actualizado',
            'data' => $holiday,
        ], 201);
    }

    /**
     * DELETE /api/v1/empresa/festivos/{id}
     * Elimina un festivo.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if ($user->role !== 'admin') {
            return response()->json(['message' => 'Solo el administrador puede gestionar festivos'], 403);
        }

        $holiday = Holiday::where('empresa_id', $user->empresa_id)
            ->where('id', $id)
            ->firstOrFail();

        $holiday->delete();

        return response()->json([
            'message' => 'Festivo eliminado',
        ]);
    }

    /**
     * POST /api/v1/empresa/festivos/cargar-mexico
     * Carga los 7 festivos oficiales de México para el año indicado (default año actual).
     */
    public function loadMexicoHolidays(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->role !== 'admin') {
            return response()->json(['message' => 'Solo el administrador puede gestionar festivos'], 403);
        }

        $year = $request->input('year', now()->year);

        $mexicoHolidays = [
            ['name' => 'Año Nuevo', 'month_day' => '01-01'],
            ['name' => 'Día de la Constitución', 'month_day' => '02-05'],
            ['name' => 'Natalicio de Benito Juárez', 'month_day' => '03-21'],
            ['name' => 'Día del Trabajo', 'month_day' => '05-01'],
            ['name' => 'Día de la Independencia', 'month_day' => '09-16'],
            ['name' => 'Día de la Revolución', 'month_day' => '11-20'],
            ['name' => 'Navidad', 'month_day' => '12-25'],
        ];

        $created = 0;
        $data = [];

        foreach ($mexicoHolidays as $h) {
            $date = "{$year}-{$h['month_day']}";

            $holiday = Holiday::updateOrCreate(
                [
                    'empresa_id' => $user->empresa_id,
                    'date' => $date,
                ],
                [
                    'name' => $h['name'],
                    'is_paid' => true,
                ]
            );

            if ($holiday->wasRecentlyCreated) {
                $created++;
            }

            $data[] = $holiday;
        }

        // Auditoría
        ActivityLogger::log(
            $user->empresa_id,
            $user->id,
            null,
            'festivos.cargar_mexico',
            'holiday',
            null,
            ['created' => $created, 'year' => $year],
            $request
        );

        return response()->json([
            'message' => "{$created} festivos creados",
            'created' => $created,
            'data' => $data,
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MealSchedule;
use App\Models\MealScheduleChangeRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class MealScheduleChangeRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        $empresaId = $request->header('X-Empresa-ID') ?? $user->empresa_id;

        $isAdmin = $user->hasRole(['admin', 'rh', 'supervisor']);
        $query = MealScheduleChangeRequest::with(['empleado.user', 'reviewer'])
            ->where('empresa_id', $empresaId);

        if (! $isAdmin) {
            $query->where('empleado_id', $user->empleado_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $requests = $query->latest()->paginate($request->input('per_page', 20));

        return response()->json([
            'data' => $requests->items(),
            'meta' => [
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
                'per_page' => $requests->perPage(),
                'total' => $requests->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = Auth::user();
        $empleado = $user->empleado;

        if (! $empleado) {
            return response()->json(['message' => 'Empleado no encontrado'], 404);
        }

        $validated = $request->validate([
            'requested_meal_start_time' => ['required', 'date_format:H:i'],
            'duration_minutes' => ['required', 'integer', 'min:10', 'max:120'],
            'justification' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        $currentSchedule = MealSchedule::where('empresa_id', $empleado->empresa_id)
            ->where('employee_id', $empleado->id)
            ->first();

        $currentMealStartTime = $currentSchedule?->meal_start_time;

        $changeRequest = MealScheduleChangeRequest::create([
            'id' => (string) Str::uuid(),
            'empresa_id' => $empleado->empresa_id,
            'empleado_id' => $empleado->id,
            'current_meal_start_time' => $currentMealStartTime,
            'requested_meal_start_time' => $validated['requested_meal_start_time'],
            'duration_minutes' => $validated['duration_minutes'],
            'justification' => $validated['justification'],
            'status' => 'pending',
        ]);

        return response()->json([
            'message' => 'Solicitud enviada',
            'data' => $changeRequest->load(['empleado.user', 'reviewer']),
        ], 201);
    }

    public function show(MealScheduleChangeRequest $changeRequest): JsonResponse
    {
        $user = Auth::user();
        $isAdmin = $user->hasRole(['admin', 'rh', 'supervisor']);

        if (! $isAdmin && $changeRequest->empleado_id !== $user->empleado_id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        return response()->json([
            'data' => $changeRequest->load(['empleado.user', 'reviewer']),
        ]);
    }

    public function review(Request $request, MealScheduleChangeRequest $changeRequest): JsonResponse
    {
        $user = Auth::user();

        if (! $user->hasRole(['admin', 'rh', 'supervisor'])) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        if ($changeRequest->status !== 'pending') {
            return response()->json(['message' => 'La solicitud ya fue revisada'], 422);
        }

        $validated = $request->validate([
            'status' => ['required', 'in:approved,rejected'],
            'reviewer_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $changeRequest->status = $validated['status'];
        $changeRequest->reviewed_by = $user->id;
        $changeRequest->reviewed_at = now();
        $changeRequest->reviewer_note = $validated['reviewer_note'] ?? null;
        $changeRequest->save();

        if ($validated['status'] === 'approved') {
            $schedule = MealSchedule::firstOrNew([
                'empresa_id' => $changeRequest->empresa_id,
                'employee_id' => $changeRequest->empleado_id,
            ]);
            $schedule->meal_start_time = $changeRequest->requested_meal_start_time;
            $schedule->duration_minutes = $changeRequest->duration_minutes;
            $schedule->save();
        }

        return response()->json([
            'message' => $validated['status'] === 'approved' ? 'Solicitud aprobada' : 'Solicitud rechazada',
            'data' => $changeRequest->load(['empleado.user', 'reviewer']),
        ]);
    }

    public function destroy(MealScheduleChangeRequest $changeRequest): JsonResponse
    {
        $user = Auth::user();

        if ($changeRequest->empleado_id !== $user->empleado_id && ! $user->hasRole(['admin', 'rh', 'supervisor'])) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        if ($changeRequest->status !== 'pending') {
            return response()->json(['message' => 'Solo se puede cancelar una solicitud pendiente'], 422);
        }

        $changeRequest->delete();

        return response()->json(['message' => 'Solicitud cancelada']);
    }
}

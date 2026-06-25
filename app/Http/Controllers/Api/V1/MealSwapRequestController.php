<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Empleado;
use App\Models\MealSchedule;
use App\Models\MealSwapRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class MealSwapRequestController extends Controller
{
    public function store(Request $request)
    {
        $u = $request->user();
        $empresaId = $u->empresa_id;

        $data = $request->validate([
            'receptor_id' => ['required', 'uuid'],
            'fecha' => ['required', 'date_format:Y-m-d'],
        ]);

        $solicitante = Empleado::where('empresa_id', $empresaId)->where('user_id', $u->id)->first();
        if (! $solicitante) {
            return response()->json(['message' => 'Empleado no vinculado'], 404);
        }

        $receptor = Empleado::where('empresa_id', $empresaId)->where('id', $data['receptor_id'])->first();
        if (! $receptor) {
            return response()->json(['message' => 'Receptor no encontrado'], 404);
        }

        if ($solicitante->id === $receptor->id) {
            return response()->json(['message' => 'No puedes solicitar cambio contigo mismo'], 422);
        }

        // Verificar que no exista ya una solicitud activa para esa fecha
        $exists = MealSwapRequest::where('empresa_id', $empresaId)
            ->where('fecha', $data['fecha'])
            ->whereIn('status', ['pending', 'accepted'])
            ->where(function ($q) use ($solicitante, $receptor) {
                $q->where('solicitante_id', $solicitante->id)
                    ->orWhere('solicitante_id', $receptor->id)
                    ->orWhere('receptor_id', $solicitante->id)
                    ->orWhere('receptor_id', $receptor->id);
            })
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'Ya existe una solicitud activa para esa fecha con uno de los participantes'], 409);
        }

        $swap = MealSwapRequest::create([
            'id' => Str::uuid(),
            'empresa_id' => $empresaId,
            'solicitante_id' => $solicitante->id,
            'receptor_id' => $receptor->id,
            'fecha' => $data['fecha'],
            'status' => 'pending',
        ]);

        return response()->json([
            'message' => 'Solicitud de cambio enviada',
            'swap' => $swap->load(['solicitante.user', 'receptor.user']),
        ], 201);
    }

    public function myRequests(Request $request)
    {
        $u = $request->user();
        $empresaId = $u->empresa_id;

        $emp = Empleado::where('empresa_id', $empresaId)->where('user_id', $u->id)->first();
        if (! $emp) {
            return response()->json(['message' => 'Empleado no vinculado'], 404);
        }

        $swaps = MealSwapRequest::where('empresa_id', $empresaId)
            ->where(function ($q) use ($emp) {
                $q->where('solicitante_id', $emp->id)
                    ->orWhere('receptor_id', $emp->id);
            })
            ->orderByDesc('created_at')
            ->with(['solicitante.user', 'receptor.user', 'reviewedBy'])
            ->get();

        return response()->json($swaps);
    }

    public function accept(Request $request, string $id)
    {
        $u = $request->user();
        $empresaId = $u->empresa_id;

        $emp = Empleado::where('empresa_id', $empresaId)->where('user_id', $u->id)->first();
        if (! $emp) {
            return response()->json(['message' => 'Empleado no vinculado'], 404);
        }

        $swap = MealSwapRequest::where('empresa_id', $empresaId)
            ->where('id', $id)
            ->where('receptor_id', $emp->id)
            ->where('status', 'pending')
            ->first();

        if (! $swap) {
            return response()->json(['message' => 'Solicitud no encontrada o no puedes aceptarla'], 404);
        }

        $swap->status = 'accepted';
        $swap->save();

        return response()->json([
            'message' => 'Solicitud aceptada. Esperando aprobación del supervisor.',
            'swap' => $swap->load(['solicitante.user', 'receptor.user']),
        ]);
    }

    public function pending(Request $request)
    {
        Gate::authorize('supervisor');
        $u = $request->user();

        $swaps = MealSwapRequest::where('empresa_id', $u->empresa_id)
            ->where('status', 'accepted')
            ->orderByDesc('created_at')
            ->with(['solicitante.user', 'receptor.user'])
            ->get();

        return response()->json($swaps);
    }

    public function review(Request $request, string $id)
    {
        Gate::authorize('supervisor');
        $u = $request->user();
        $empresaId = $u->empresa_id;

        $data = $request->validate([
            'status' => ['required', 'in:approved,rejected'],
        ]);

        $swap = MealSwapRequest::where('empresa_id', $empresaId)
            ->where('id', $id)
            ->where('status', 'accepted')
            ->first();

        if (! $swap) {
            return response()->json(['message' => 'Solicitud no encontrada o no está lista para revisión'], 404);
        }

        if ($data['status'] === 'approved') {
            // Intercambiar meal_schedules
            $scheduleA = MealSchedule::where('empresa_id', $empresaId)->where('employee_id', $swap->solicitante_id)->first();
            $scheduleB = MealSchedule::where('empresa_id', $empresaId)->where('employee_id', $swap->receptor_id)->first();

            if ($scheduleA && $scheduleB) {
                $temp = $scheduleA->meal_start_time;
                $scheduleA->meal_start_time = $scheduleB->meal_start_time;
                $scheduleB->meal_start_time = $temp;
                $scheduleA->save();
                $scheduleB->save();
            }
        }

        $swap->status = $data['status'];
        $swap->reviewed_by = $u->id;
        $swap->reviewed_at = now();
        $swap->save();

        return response()->json([
            'message' => $data['status'] === 'approved' ? 'Cambio aprobado y horarios intercambiados' : 'Cambio rechazado',
            'swap' => $swap->load(['solicitante.user', 'receptor.user', 'reviewedBy']),
        ]);
    }
}

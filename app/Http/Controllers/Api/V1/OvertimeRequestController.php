<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Empleado;
use App\Models\OvertimeRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class OvertimeRequestController extends Controller
{
    public function store(Request $request)
    {
        $u = $request->user();
        $empresaId = $u->empresa_id;

        $data = $request->validate([
            'fecha' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'motivo' => ['required', 'string', 'max:500'],
            'minutos_solicitados' => ['required', 'integer', 'min:15', 'max:720'],
        ]);

        $emp = Empleado::where('empresa_id', $empresaId)->where('user_id', $u->id)->first();
        if (! $emp) {
            return response()->json(['message' => 'Empleado no vinculado'], 404);
        }

        $ot = OvertimeRequest::create([
            'id' => Str::uuid(),
            'empresa_id' => $empresaId,
            'empleado_id' => $emp->id,
            'fecha' => $data['fecha'],
            'motivo' => $data['motivo'],
            'minutos_solicitados' => (int) $data['minutos_solicitados'],
            'status' => 'pending',
        ]);

        return response()->json([
            'message' => 'Solicitud de horas extra enviada',
            'request' => $ot->load('empleado.user'),
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

        $requests = OvertimeRequest::where('empresa_id', $empresaId)
            ->where('empleado_id', $emp->id)
            ->orderByDesc('created_at')
            ->with('empleado.user')
            ->get();

        return response()->json($requests);
    }

    public function pending(Request $request)
    {
        Gate::authorize('supervisor');
        $u = $request->user();

        $requests = OvertimeRequest::where('empresa_id', $u->empresa_id)
            ->where('status', 'pending')
            ->orderByDesc('created_at')
            ->with('empleado.user')
            ->get();

        return response()->json($requests);
    }

    public function review(Request $request, string $id)
    {
        Gate::authorize('supervisor');
        $u = $request->user();
        $empresaId = $u->empresa_id;

        $data = $request->validate([
            'status' => ['required', 'in:approved,rejected'],
            'reviewer_note' => ['nullable', 'string', 'max:500'],
        ]);

        $ot = OvertimeRequest::where('empresa_id', $empresaId)
            ->where('id', $id)
            ->where('status', 'pending')
            ->first();

        if (! $ot) {
            return response()->json(['message' => 'Solicitud no encontrada o ya fue revisada'], 404);
        }

        $ot->status = $data['status'];
        $ot->reviewed_by = $u->id;
        $ot->reviewer_note = $data['reviewer_note'] ?? null;
        $ot->reviewed_at = now();
        $ot->save();

        return response()->json([
            'message' => $data['status'] === 'approved' ? 'Horas extra aprobadas' : 'Horas extra rechazadas',
            'request' => $ot->load(['empleado.user', 'reviewedBy']),
        ]);
    }
}

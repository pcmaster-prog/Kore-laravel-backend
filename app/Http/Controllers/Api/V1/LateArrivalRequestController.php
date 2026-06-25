<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\SendPushNotification;
use App\Jobs\SendPushNotificationToManagers;
use App\Models\AttendanceDay;
use App\Models\Empleado;
use App\Models\LateArrivalRequest;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class LateArrivalRequestController extends Controller
{
    /**
     * Empleado: solicitar oportunidad de llegada tarde para hoy.
     */
    public function store(Request $request)
    {
        $u = $request->user();
        Gate::authorize('empleado');

        $data = $request->validate([
            'motivo' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $emp = Empleado::where('empresa_id', $u->empresa_id)
            ->where('user_id', $u->id)
            ->first();

        if (! $emp) {
            return response()->json(['message' => 'Empleado no vinculado'], 404);
        }

        $today = now()->toDateString();
        $empresaId = $u->empresa_id;

        // No permitir si ya marcó entrada hoy
        $alreadyCheckedIn = AttendanceDay::where('empresa_id', $empresaId)
            ->where('empleado_id', $emp->id)
            ->whereDate('date', $today)
            ->whereNotNull('first_check_in_at')
            ->exists();

        if ($alreadyCheckedIn) {
            return response()->json([
                'message' => 'Ya registraste tu entrada hoy. No necesitas oportunidad.',
                'code' => 'ALREADY_CHECKED_IN',
            ], 409);
        }

        // Solo una solicitud pendiente por día
        $pendingExists = LateArrivalRequest::where('empresa_id', $empresaId)
            ->where('empleado_id', $emp->id)
            ->whereDate('date', $today)
            ->where('status', 'pending')
            ->exists();

        if ($pendingExists) {
            return response()->json([
                'message' => 'Ya tienes una solicitud de oportunidad pendiente para hoy.',
                'code' => 'LATE_REQUEST_PENDING_EXISTS',
            ], 409);
        }

        // No permitir si ya existe una aprobada para hoy
        $approvedExists = LateArrivalRequest::where('empresa_id', $empresaId)
            ->where('empleado_id', $emp->id)
            ->whereDate('date', $today)
            ->where('status', 'approved')
            ->exists();

        if ($approvedExists) {
            return response()->json([
                'message' => 'Ya tienes una oportunidad aprobada para hoy. Puedes marcar tu entrada.',
                'code' => 'LATE_REQUEST_ALREADY_APPROVED',
            ], 409);
        }

        $req = LateArrivalRequest::create([
            'empresa_id' => $empresaId,
            'empleado_id' => $emp->id,
            'user_id' => $u->id,
            'date' => $today,
            'motivo' => $data['motivo'],
            'status' => 'pending',
        ]);

        ActivityLogger::log(
            $empresaId,
            $u->id,
            $emp->id,
            'late_arrival_request.created',
            'late_arrival_request',
            $req->id,
            [
                'employee_name' => $emp->full_name ?? $u->name,
                'motivo' => $req->motivo,
                'date' => $today,
            ],
            $request
        );

        try {
            SendPushNotificationToManagers::dispatch(
                $empresaId,
                '🚨 Solicitud de oportunidad',
                ($emp->full_name ?? $u->name).' solicitó oportunidad de entrada para hoy.',
                [
                    'type' => 'late_arrival_request.pending',
                    'empleado_id' => $emp->id,
                    'request_id' => $req->id,
                ]
            );
        } catch (\Throwable $e) {
            Log::error('Error notificando solicitud de oportunidad: '.$e->getMessage());
        }

        return response()->json([
            'message' => 'Solicitud enviada. Espera la respuesta del administrador.',
            'request' => $this->present($req),
        ], 201);
    }

    /**
     * Empleado: listar mis solicitudes.
     */
    public function myRequests(Request $request)
    {
        $u = $request->user();
        Gate::authorize('empleado');

        $emp = Empleado::where('empresa_id', $u->empresa_id)
            ->where('user_id', $u->id)
            ->first();

        if (! $emp) {
            return response()->json(['data' => []]);
        }

        $items = LateArrivalRequest::where('empresa_id', $u->empresa_id)
            ->where('empleado_id', $emp->id)
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();

        return response()->json(['data' => $items->map(fn ($r) => $this->present($r))]);
    }

    /**
     * Admin/Supervisor: listar solicitudes pendientes.
     */
    public function pending(Request $request)
    {
        $u = $request->user();
        Gate::authorize('supervisor');

        $items = LateArrivalRequest::with(['empleado', 'user'])
            ->where('empresa_id', $u->empresa_id)
            ->where('status', 'pending')
            ->whereDate('date', '>=', now()->toDateString())
            ->orderBy('date')
            ->orderBy('created_at')
            ->get();

        return response()->json(['data' => $items->map(fn ($r) => $this->present($r, true))]);
    }

    /**
     * Admin/Supervisor: aprobar o rechazar una solicitud.
     */
    public function review(Request $request, string $id)
    {
        $u = $request->user();
        Gate::authorize('supervisor');

        $data = $request->validate([
            'status' => ['required', Rule::in(['approved', 'rejected'])],
            'reviewer_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $req = LateArrivalRequest::where('empresa_id', $u->empresa_id)
            ->where('id', $id)
            ->first();

        if (! $req) {
            return response()->json(['message' => 'Solicitud no encontrada'], 404);
        }

        if ($req->status !== 'pending') {
            return response()->json([
                'message' => 'La solicitud ya fue resuelta.',
                'code' => 'LATE_REQUEST_ALREADY_RESOLVED',
            ], 409);
        }

        $req->status = $data['status'];
        $req->reviewed_by = $u->id;
        $req->reviewed_at = now();
        $req->reviewer_note = $data['reviewer_note'] ?? null;
        $req->save();

        $emp = $req->empleado;
        $employeeUser = $emp?->user;
        $employeeName = $emp?->full_name ?? $employeeUser?->name ?? 'Empleado';

        ActivityLogger::log(
            $u->empresa_id,
            $u->id,
            $emp?->id,
            'late_arrival_request.'.$req->status,
            'late_arrival_request',
            $req->id,
            [
                'employee_name' => $employeeName,
                'reviewer_name' => $u->name,
                'reviewer_note' => $req->reviewer_note,
                'date' => $req->date->toDateString(),
            ],
            $request
        );

        // Notificar al empleado
        if ($employeeUser) {
            try {
                $title = $req->status === 'approved'
                    ? '✅ Oportunidad aprobada'
                    : '❌ Oportunidad rechazada';
                $body = $req->status === 'approved'
                    ? 'Tu solicitud de oportunidad fue aprobada. Ya puedes marcar tu entrada.'
                    : 'Tu solicitud de oportunidad fue rechazada. No podrás marcar entrada hoy.';

                SendPushNotification::dispatch(
                    $employeeUser->id,
                    $title,
                    $body,
                    [
                        'type' => 'late_arrival_request.'.$req->status,
                        'request_id' => $req->id,
                    ]
                );
            } catch (\Throwable $e) {
                Log::error('Error notificando resolución de oportunidad: '.$e->getMessage());
            }
        }

        return response()->json([
            'message' => $req->status === 'approved' ? 'Oportunidad aprobada' : 'Oportunidad rechazada',
            'request' => $this->present($req, true),
        ]);
    }

    /**
     * Helper para verificar si un empleado tiene oportunidad aprobada hoy.
     */
    public static function hasApprovedToday(string $empleadoId, string $date): bool
    {
        return LateArrivalRequest::where('empleado_id', $empleadoId)
            ->where('date', $date)
            ->where('status', 'approved')
            ->exists();
    }

    private function present(LateArrivalRequest $req, bool $includeReviewer = false): array
    {
        $base = [
            'id' => $req->id,
            'empresa_id' => $req->empresa_id,
            'empleado_id' => $req->empleado_id,
            'empleado_name' => $req->empleado?->full_name ?? $req->user?->name ?? '—',
            'user_id' => $req->user_id,
            'date' => $req->date?->toDateString(),
            'motivo' => $req->motivo,
            'status' => $req->status,
            'reviewer_note' => $req->reviewer_note,
            'created_at' => $req->created_at?->toISOString(),
            'reviewed_at' => $req->reviewed_at?->toISOString(),
        ];

        if ($includeReviewer) {
            $base['reviewed_by'] = $req->reviewed_by;
            $base['reviewer_name'] = $req->reviewer?->name;
        }

        return $base;
    }
}

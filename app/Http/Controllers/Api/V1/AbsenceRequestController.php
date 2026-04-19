<?php
// AbsenceRequestController: gestión de solicitudes de ausencia justificada
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AttendanceAbsenceRequest;
use App\Models\Empleado;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class AbsenceRequestController extends Controller
{
    // ─── EMPLEADO: Crear solicitud ────────────────────────────────────────────

    /**
     * POST /asistencia/ausencias
     * Empleado (o supervisor) solicita ausentarse un día con justificación.
     */
    public function store(Request $request)
    {
        $u = $request->user();
        $empresaId = $u->empresa_id;

        $emp = Empleado::where('empresa_id', $empresaId)
            ->where('user_id', $u->id)
            ->first();

        if (!$emp) {
            return response()->json(['message' => 'Empleado no vinculado'], 404);
        }

        $data = $request->validate([
            'date'   => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'motivo' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        // Evitar duplicados para la misma fecha
        $existing = AttendanceAbsenceRequest::where('empresa_id', $empresaId)
            ->where('empleado_id', $emp->id)
            ->where('date', $data['date'])
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Ya tienes una solicitud de ausencia para esa fecha.',
                'request' => $this->presentRequest($existing),
            ], 409);
        }

        $req = AttendanceAbsenceRequest::create([
            'empresa_id'  => $empresaId,
            'empleado_id' => $emp->id,
            'date'        => $data['date'],
            'motivo'      => $data['motivo'],
            'status'      => 'pending',
        ]);

        // Notificar a admins
        try {
            app(NotificationService::class)->sendToManagers(
                empresaId: $empresaId,
                title: '📋 Solicitud de ausencia',
                body: ($emp->full_name) . ' solicita ausencia el ' . \Carbon\Carbon::parse($data['date'])->format('d/m/Y') . ': "' . \Str::limit($data['motivo'], 60) . '"',
                data: [
                    'type'       => 'absence.new_request',
                    'request_id' => $req->id,
                    'empleado_id'=> $emp->id,
                    'date'       => $data['date'],
                ]
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Error notificando solicitud de ausencia: ' . $e->getMessage());
        }

        return response()->json([
            'message' => 'Solicitud enviada correctamente. Espera la respuesta del administrador.',
            'request' => $this->presentRequest($req),
        ], 201);
    }

    /**
     * GET /asistencia/ausencias
     * Empleado ve sus propias solicitudes.
     */
    public function myRequests(Request $request)
    {
        $u = $request->user();
        $empresaId = $u->empresa_id;

        $emp = Empleado::where('empresa_id', $empresaId)->where('user_id', $u->id)->first();
        if (!$emp) return response()->json(['data' => []]);

        $requests = AttendanceAbsenceRequest::where('empresa_id', $empresaId)
            ->where('empleado_id', $emp->id)
            ->orderByDesc('date')
            ->limit(30)
            ->get()
            ->map(fn($r) => $this->presentRequest($r));

        return response()->json(['data' => $requests]);
    }

    // ─── ADMIN/SUPERVISOR: Lista de pendientes ────────────────────────────────

    /**
     * GET /asistencia/ausencias/pendientes
     * Admin/Supervisor ve solicitudes pendientes de su empresa.
     */
    public function pending(Request $request)
    {
        $u = $request->user();
        if (!in_array($u->role, ['admin', 'supervisor'])) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $empresaId = $u->empresa_id;

        $requests = AttendanceAbsenceRequest::where('empresa_id', $empresaId)
            ->with(['empleado'])
            ->orderBy('date')
            ->get()
            ->map(fn($r) => $this->presentRequest($r, true));

        return response()->json(['data' => $requests]);
    }

    /**
     * PATCH /asistencia/ausencias/{id}
     * Admin/Supervisor aprueba o rechaza una solicitud.
     */
    public function review(Request $request, string $id)
    {
        $u = $request->user();
        if (!in_array($u->role, ['admin', 'supervisor'])) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $data = $request->validate([
            'status'        => ['required', 'in:approved,rejected'],
            'reviewer_note' => ['nullable', 'string', 'max:400'],
        ]);

        $req = AttendanceAbsenceRequest::where('empresa_id', $u->empresa_id)
            ->findOrFail($id);

        if ($req->status !== 'pending') {
            return response()->json(['message' => 'Esta solicitud ya fue revisada.'], 409);
        }

        $req->update([
            'status'        => $data['status'],
            'reviewed_by'   => $u->id,
            'reviewed_at'   => now(),
            'reviewer_note' => $data['reviewer_note'] ?? null,
        ]);

        // Notificar al empleado
        try {
            $emp = Empleado::find($req->empleado_id);
            if ($emp && $emp->user_id) {
                $isApproved = $data['status'] === 'approved';
                app(NotificationService::class)->sendToUser(
                    userId: $emp->user_id,
                    title: $isApproved ? '✅ Ausencia aprobada' : '❌ Ausencia rechazada',
                    body: 'Tu solicitud del ' . $req->date->format('d/m/Y') . ' fue ' . ($isApproved ? 'aprobada' : 'rechazada') . ($data['reviewer_note'] ? ': "' . $data['reviewer_note'] . '"' : '.'),
                    data: [
                        'type'       => 'absence.reviewed',
                        'request_id' => $req->id,
                        'status'     => $data['status'],
                    ]
                );
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Error notificando respuesta de ausencia: ' . $e->getMessage());
        }

        return response()->json([
            'message' => $data['status'] === 'approved' ? 'Solicitud aprobada.' : 'Solicitud rechazada.',
            'request' => $this->presentRequest($req->fresh(['empleado'])),
        ]);
    }

    // ─── Helper ───────────────────────────────────────────────────────────────

    private function presentRequest(AttendanceAbsenceRequest $r, bool $withEmpleado = false): array
    {
        $data = [
            'id'            => $r->id,
            'date'          => $r->date?->toDateString(),
            'motivo'        => $r->motivo,
            'status'        => $r->status,
            'reviewer_note' => $r->reviewer_note,
            'reviewed_at'   => $r->reviewed_at?->toISOString(),
            'created_at'    => $r->created_at?->toISOString(),
        ];

        if ($withEmpleado && $r->relationLoaded('empleado')) {
            $data['empleado_id']   = $r->empleado_id;
            $data['empleado_name'] = $r->empleado?->full_name ?? '—';
        }

        return $data;
    }
}

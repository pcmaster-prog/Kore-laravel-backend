<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\SendPushNotification;
use App\Jobs\SendPushNotificationToManagers;
use App\Models\AttendanceCorrectionRequest;
use App\Models\AttendanceDay;
use App\Models\AttendanceEvent;
use App\Models\Empleado;
use App\Services\ActivityLogger;
use App\Services\AttendanceService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Solicitudes de corrección de asistencia: el empleado reporta
 * "olvidé marcar mi entrada/salida a las HH:MM" y el admin/supervisor
 * aprueba (se aplica la hora al día) o rechaza.
 */
class AttendanceCorrectionRequestController extends Controller
{
    /** Días hacia atrás que el empleado puede corregir. */
    private const MAX_DAYS_BACK = 14;

    public function store(Request $request)
    {
        $u = $request->user();
        Gate::authorize('empleado');

        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'type' => ['required', Rule::in(['check_in', 'check_out'])],
            'requested_time' => ['required', 'date_format:H:i'],
            'motivo' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $emp = Empleado::where('empresa_id', $u->empresa_id)
            ->where('user_id', $u->id)
            ->first();

        if (! $emp) {
            return response()->json(['message' => 'Empleado no vinculado'], 404);
        }

        $empresaId = $u->empresa_id;
        $date = Carbon::parse($data['date']);

        if ($date->isFuture()) {
            return response()->json(['message' => 'No puedes corregir una fecha futura.'], 422);
        }

        if ($date->lt(now()->subDays(self::MAX_DAYS_BACK)->startOfDay())) {
            return response()->json([
                'message' => 'Solo puedes solicitar correcciones de los últimos '.self::MAX_DAYS_BACK.' días.',
            ], 422);
        }

        // Si la hora solicitada es de hoy, no puede ser futura
        if ($date->isToday()) {
            $requestedAt = Carbon::parse($data['date'].' '.$data['requested_time']);
            if ($requestedAt->isFuture()) {
                return response()->json(['message' => 'La hora solicitada aún no ha ocurrido.'], 422);
            }
        }

        $day = AttendanceDay::where('empresa_id', $empresaId)
            ->where('empleado_id', $emp->id)
            ->whereDate('date', $data['date'])
            ->first();

        if ($day && self::markAlreadyRecorded($day, $data['type'])) {
            $label = $data['type'] === 'check_in' ? 'entrada' : 'salida';
            $at = $data['type'] === 'check_in' ? $day->first_check_in_at : $day->last_check_out_at;

            return response()->json([
                'message' => 'Ese día ya tiene '.$label.' registrada ('.$at->format('H:i').').',
                'code' => $data['type'] === 'check_in' ? 'ALREADY_HAS_CHECK_IN' : 'ALREADY_HAS_CHECK_OUT',
            ], 409);
        }

        $pendingExists = AttendanceCorrectionRequest::where('empresa_id', $empresaId)
            ->where('empleado_id', $emp->id)
            ->whereDate('date', $data['date'])
            ->where('type', $data['type'])
            ->where('status', 'pending')
            ->exists();

        if ($pendingExists) {
            return response()->json([
                'message' => 'Ya tienes una solicitud pendiente para ese día.',
                'code' => 'CORRECTION_PENDING_EXISTS',
            ], 409);
        }

        $req = AttendanceCorrectionRequest::create([
            'empresa_id' => $empresaId,
            'empleado_id' => $emp->id,
            'user_id' => $u->id,
            'date' => $data['date'],
            'type' => $data['type'],
            'requested_time' => $data['requested_time'],
            'motivo' => $data['motivo'],
            'status' => 'pending',
        ]);

        ActivityLogger::log(
            $empresaId,
            $u->id,
            $emp->id,
            'attendance_correction.created',
            'attendance_correction_request',
            $req->id,
            [
                'employee_name' => $emp->full_name ?? $u->name,
                'date' => $data['date'],
                'type' => $data['type'],
                'requested_time' => $data['requested_time'],
                'motivo' => $req->motivo,
            ],
            $request
        );

        try {
            $label = $data['type'] === 'check_in' ? 'entrada' : 'salida';
            SendPushNotificationToManagers::dispatch(
                $empresaId,
                '📝 Corrección de asistencia',
                ($emp->full_name ?? $u->name)." olvidó marcar su {$label} del {$date->format('d/m')} ({$data['requested_time']}).",
                [
                    'type' => 'attendance_correction.pending',
                    'empleado_id' => $emp->id,
                    'request_id' => $req->id,
                ]
            );
        } catch (\Throwable $e) {
            Log::error('Error notificando solicitud de corrección: '.$e->getMessage());
        }

        return response()->json([
            'message' => 'Solicitud enviada. Tu administrador la revisará.',
            'request' => $this->present($req),
        ], 201);
    }

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

        $items = AttendanceCorrectionRequest::where('empresa_id', $u->empresa_id)
            ->where('empleado_id', $emp->id)
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();

        return response()->json(['data' => $items->map(fn ($r) => $this->present($r))]);
    }

    public function pending(Request $request)
    {
        $u = $request->user();
        Gate::authorize('supervisor');

        $items = AttendanceCorrectionRequest::with(['empleado', 'user', 'reviewer'])
            ->where('empresa_id', $u->empresa_id)
            ->where(function ($q) {
                $q->where('status', 'pending')
                    ->orWhere('reviewed_at', '>=', now()->subDays(7));
            })
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderBy('date')
            ->orderBy('created_at')
            ->get();

        return response()->json(['data' => $items->map(fn ($r) => $this->present($r, true))]);
    }

    public function review(Request $request, string $id)
    {
        $u = $request->user();
        Gate::authorize('supervisor');

        $data = $request->validate([
            'status' => ['required', Rule::in(['approved', 'rejected'])],
            'reviewer_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $req = AttendanceCorrectionRequest::with('empleado')
            ->where('empresa_id', $u->empresa_id)
            ->where('id', $id)
            ->first();

        if (! $req) {
            return response()->json(['message' => 'Solicitud no encontrada'], 404);
        }

        if ($req->status !== 'pending') {
            return response()->json([
                'message' => 'La solicitud ya fue resuelta.',
                'code' => 'CORRECTION_ALREADY_RESOLVED',
            ], 409);
        }

        if (! $this->resolve($req, $data['status'], $data['reviewer_note'] ?? null, $request)) {
            return response()->json([
                'message' => 'Ese día ya tiene una marca real registrada después de la solicitud. Revísala en Asistencia o rechaza la solicitud.',
                'code' => 'CORRECTION_ALREADY_RECORDED',
            ], 409);
        }

        return response()->json([
            'message' => $req->status === 'approved' ? 'Corrección aplicada' : 'Solicitud rechazada',
            'request' => $this->present($req->fresh(['empleado', 'user', 'reviewer']), true),
        ]);
    }

    /**
     * Aprueba todas las pendientes de la empresa en un solo paso.
     */
    public function approveAll(Request $request)
    {
        $u = $request->user();
        Gate::authorize('supervisor');

        $items = AttendanceCorrectionRequest::with('empleado')
            ->where('empresa_id', $u->empresa_id)
            ->where('status', 'pending')
            ->orderBy('date')
            ->get();

        $approved = 0;
        $skipped = 0;
        foreach ($items as $req) {
            if ($this->resolve($req, 'approved', null, $request)) {
                $approved++;
            } else {
                $skipped++;
            }
        }

        $message = "Se aprobaron {$approved} solicitud(es)";
        if ($skipped > 0) {
            $message .= "; {$skipped} omitida(s) porque el día ya tiene esa marca registrada";
        }

        return response()->json([
            'message' => $message,
            'approved' => $approved,
            'skipped' => $skipped,
        ]);
    }

    /**
     * Devuelve false (sin tocar la solicitud) si se intenta aprobar y el día ya
     * tiene esa marca registrada de verdad después de que se creó la solicitud:
     * aprobar sobreescribiría la marca real del empleado.
     */
    private function resolve(AttendanceCorrectionRequest $req, string $status, ?string $note, Request $request): bool
    {
        $u = $request->user();

        $applied = DB::transaction(function () use ($req, $status, $note, $u, $request) {
            if ($status === 'approved') {
                $day = AttendanceDay::where('empresa_id', $req->empresa_id)
                    ->where('empleado_id', $req->empleado_id)
                    ->whereDate('date', $req->date->toDateString())
                    ->lockForUpdate()
                    ->first();

                if ($day && self::markAlreadyRecorded($day, $req->type)) {
                    return false;
                }
            }

            $req->status = $status;
            $req->reviewed_by = $u->id;
            $req->reviewed_at = now();
            $req->reviewer_note = $note;
            $req->save();

            if ($status === 'approved') {
                AttendanceService::applyManualTimes(
                    $req->empresa_id,
                    $req->empleado_id,
                    $req->date->toDateString(),
                    $req->type === 'check_in' ? $req->requested_time : null,
                    $req->type === 'check_out' ? $req->requested_time : null,
                    [
                        'correction_request_id' => $req->id,
                        'ip' => $request->ip(),
                        'ua' => substr((string) $request->userAgent(), 0, 250),
                    ]
                );
            }

            return true;
        });

        if (! $applied) {
            return false;
        }

        $emp = $req->empleado;
        $employeeName = $emp?->full_name ?? $req->user?->name ?? 'Empleado';

        ActivityLogger::log(
            $req->empresa_id,
            $u->id,
            $emp?->id,
            'attendance_correction.'.$status,
            'attendance_correction_request',
            $req->id,
            [
                'employee_name' => $employeeName,
                'reviewer_name' => $u->name,
                'reviewer_note' => $note,
                'date' => $req->date->toDateString(),
                'type' => $req->type,
                'requested_time' => $req->requested_time,
            ],
            $request
        );

        if ($req->user_id) {
            try {
                $label = $req->type === 'check_in' ? 'entrada' : 'salida';
                $fecha = $req->date->format('d/m');
                SendPushNotification::dispatch(
                    $req->user_id,
                    $status === 'approved' ? '✅ Corrección aplicada' : '❌ Corrección rechazada',
                    $status === 'approved'
                        ? "Tu {$label} del {$fecha} quedó registrada a las {$req->requested_time}."
                        : "Tu solicitud de {$label} del {$fecha} fue rechazada.".($note ? " Nota: {$note}" : ''),
                    [
                        'type' => 'attendance_correction.'.$status,
                        'request_id' => $req->id,
                    ]
                );
            } catch (\Throwable $e) {
                Log::error('Error notificando resolución de corrección: '.$e->getMessage());
            }
        }

        return true;
    }

    /**
     * Un día "ya tiene" la marca cuando el campo está lleno Y existe el evento
     * correspondiente (marca real del empleado o ajuste previo del admin).
     * Los días cerrados por el cierre automático no tienen evento de salida,
     * así que sí pueden corregirse.
     */
    private static function markAlreadyRecorded(AttendanceDay $day, string $type): bool
    {
        $field = $type === 'check_in' ? 'first_check_in_at' : 'last_check_out_at';
        if (! $day->{$field}) {
            return false;
        }

        return AttendanceEvent::where('attendance_day_id', $day->id)
            ->where('type', $type)
            ->exists();
    }

    private function present(AttendanceCorrectionRequest $req, bool $includeReviewer = false): array
    {
        $base = [
            'id' => $req->id,
            'empleado_id' => $req->empleado_id,
            'empleado_name' => $req->empleado?->full_name ?? $req->user?->name ?? '—',
            'date' => $req->date?->toDateString(),
            'type' => $req->type,
            'requested_time' => $req->requested_time,
            'motivo' => $req->motivo,
            'status' => $req->status,
            'reviewer_note' => $req->reviewer_note,
            'created_at' => $req->created_at?->toISOString(),
            'reviewed_at' => $req->reviewed_at?->toISOString(),
        ];

        if ($includeReviewer) {
            $base['reviewer_name'] = $req->reviewer?->name;
        }

        return $base;
    }
}

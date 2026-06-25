<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmpleadoResource;
use App\Http\Resources\EmployeeEvaluationResource;
use App\Models\DesempenoEvaluacion;
use App\Models\DesempenoPeerEvaluacion;
use App\Models\Empleado;
use App\Models\EmployeeEvaluation;
use App\Models\SemaforoConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class SemaforoController extends Controller
{
    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Calcula el semáforo a partir de una EmployeeEvaluation cargada.
     */
    private function calcularResultado(EmployeeEvaluation $evaluation): array
    {
        $evalScore = null;
        $peerScore = null;
        $finalScore = null;
        $semaforo = null;

        $hasEval = $evaluation->evaluaciones->isNotEmpty();
        $hasPeer = $evaluation->peerEvaluaciones->isNotEmpty();

        if ($hasEval) {
            $evalScore = round($evaluation->evaluaciones->avg(fn ($e) => ($e->puntualidad + $e->responsabilidad + $e->actitud_trabajo +
                 $e->orden_limpieza + $e->atencion_cliente + $e->trabajo_equipo +
                 $e->iniciativa + $e->aprendizaje_adaptacion) / 40 * 100
            ), 1);
        }

        if ($hasPeer) {
            $peerScore = round($evaluation->peerEvaluaciones->avg(fn ($p) => ($p->colaboracion + $p->puntualidad + $p->actitud + $p->comunicacion) / 4 * 100
            ), 1);
        }

        if ($hasEval && $hasPeer) {
            $finalScore = round(($evalScore * 0.70) + ($peerScore * 0.30), 1);
        } elseif ($hasEval) {
            $finalScore = $evalScore;
        } elseif ($hasPeer) {
            $finalScore = $peerScore;
        }

        if ($finalScore !== null) {
            $semaforo = match (true) {
                $finalScore >= 80 => 'verde',
                $finalScore >= 60 => 'amarillo',
                default => 'rojo',
            };
        }

        return [
            'eval_score' => $evalScore,
            'peer_score' => $peerScore,
            'final_score' => $finalScore,
            'semaforo' => $semaforo,
        ];
    }

    // ─── ADMIN ENDPOINTS ──────────────────────────────────────────────────────

    /**
     * GET /semaforo/empleados
     * Admin: lista empleados con evaluación activa o pasada.
     */
    public function index(Request $request)
    {
        Gate::authorize('admin');

        $empleados = Empleado::where('empresa_id', $request->user()->empresa_id)
            ->where('status', 'active')
            ->with(['evaluations' => function ($q) {
                $q->orderByDesc('created_at')->with(['evaluaciones', 'peerEvaluaciones']);
            }])
            ->get();

        $result = $empleados->map(function ($emp) {
            $evaluation = $emp->evaluations->first();

            $evalData = null;
            if ($evaluation) {
                $scores = $this->calcularResultado($evaluation);

                $evalData = [
                    'id' => $evaluation->id,
                    'is_active' => $evaluation->is_active,
                    'activated_at' => $evaluation->activated_at,
                    'evaluaciones_count' => $evaluation->evaluaciones->count(),
                    'peer_evaluaciones_count' => $evaluation->peerEvaluaciones->count(),
                    'semaforo' => $scores['semaforo'],
                ];
            }

            return [
                'empleado' => new EmpleadoResource($emp),
                'evaluation' => $evalData,
            ];
        });

        return response()->json($result);
    }

    /**
     * POST /semaforo/empleados/{empleadoId}/activar
     * Admin activa evaluación para un empleado.
     */
    public function activar(Request $request, string $empleadoId)
    {
        Gate::authorize('admin');

        $u = $request->user();
        // Verificar que el empleado existe y pertenece a la misma empresa
        $empleado = Empleado::where('id', $empleadoId)
            ->where('empresa_id', $u->empresa_id)
            ->firstOrFail();

        // Verificar si ya existe evaluación activa
        $existing = EmployeeEvaluation::where('empresa_id', $u->empresa_id)
            ->where('empleado_id', $empleadoId)
            ->where('is_active', true)
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Ya existe una evaluación activa para este empleado',
            ], 409);
        }

        $evaluation = EmployeeEvaluation::create([
            'empresa_id' => $u->empresa_id,
            'empleado_id' => $empleadoId,
            'activated_by' => $u->id,
            'is_active' => true,
            'activated_at' => now(),
        ]);

        return response()->json(new EmployeeEvaluationResource($evaluation), 201);
    }

    /**
     * POST /semaforo/empleados/{empleadoId}/desactivar
     * Admin desactiva evaluación.
     */
    public function desactivar(Request $request, string $empleadoId)
    {
        Gate::authorize('admin');

        $u = $request->user();
        $evaluation = EmployeeEvaluation::where('empresa_id', $u->empresa_id)
            ->where('empleado_id', $empleadoId)
            ->where('is_active', true)
            ->first();

        if (! $evaluation) {
            return response()->json([
                'message' => 'No hay evaluación activa para este empleado',
            ], 404);
        }

        $evaluation->update([
            'is_active' => false,
            'deactivated_at' => now(),
        ]);

        return response()->json(new EmployeeEvaluationResource($evaluation));
    }

    /**
     * GET /semaforo/empleados/{empleadoId}/resultado
     * Admin ve el resultado completo.
     */
    public function resultado(Request $request, string $empleadoId)
    {
        Gate::authorize('admin');

        $u = $request->user();
        // Buscar la evaluación más reciente del empleado (activa o no)
        $evaluation = EmployeeEvaluation::where('empresa_id', $u->empresa_id)
            ->where('empleado_id', $empleadoId)
            ->orderByDesc('created_at')
            ->first();

        if (! $evaluation) {
            return response()->json([
                'message' => 'No hay evaluaciones para este empleado',
            ], 404);
        }

        $evaluation->load([
            'empleado',
            'evaluaciones.evaluador',
            'peerEvaluaciones.evaluador',
        ]);

        $scores = $this->calcularResultado($evaluation);

        // Formatear evaluaciones admin/supervisor
        $evaluaciones = $evaluation->evaluaciones->map(fn ($e) => [
            'evaluador' => [
                'full_name' => $e->evaluador->name,
                'role' => $e->evaluador_rol,
            ],
            'evaluador_rol' => $e->evaluador_rol,
            'puntualidad' => $e->puntualidad,
            'responsabilidad' => $e->responsabilidad,
            'actitud_trabajo' => $e->actitud_trabajo,
            'orden_limpieza' => $e->orden_limpieza,
            'atencion_cliente' => $e->atencion_cliente,
            'trabajo_equipo' => $e->trabajo_equipo,
            'iniciativa' => $e->iniciativa,
            'aprendizaje_adaptacion' => $e->aprendizaje_adaptacion,
            'total' => $e->total,
            'porcentaje' => $e->porcentaje,
            'acciones' => $e->acciones,
            'observaciones' => $e->observaciones,
            'created_at' => $e->created_at,
        ]);

        // Formatear peer evaluaciones (admin SÍ ve quién evaluó)
        $peerEvaluaciones = $evaluation->peerEvaluaciones->map(fn ($p) => [
            'evaluador' => [
                'full_name' => $p->evaluador->name,
            ],
            'colaboracion' => $p->colaboracion,
            'puntualidad' => $p->puntualidad,
            'actitud' => $p->actitud,
            'comunicacion' => $p->comunicacion,
            'promedio' => $p->promedio,
            'porcentaje' => $p->porcentaje,
        ]);

        return response()->json([
            'empleado' => new EmpleadoResource($evaluation->empleado),
            'is_active' => $evaluation->is_active,
            'activated_at' => $evaluation->activated_at,
            'deactivated_at' => $evaluation->deactivated_at,
            'final_score' => $scores['final_score'],
            'semaforo' => $scores['semaforo'],
            'eval_score' => $scores['eval_score'],
            'peer_score' => $scores['peer_score'],
            'evaluaciones' => $evaluaciones,
            'peer_evaluaciones' => $peerEvaluaciones,
            'peer_count' => $evaluation->peerEvaluaciones->count(),
        ]);
    }

    // ─── ADMIN + SUPERVISOR ENDPOINTS ─────────────────────────────────────────

    /**
     * POST /semaforo/evaluaciones
     * Admin o supervisor evalúa a un empleado.
     */
    public function evaluarAdmin(Request $request)
    {
        Gate::authorize('supervisor');

        $u = $request->user();
        $data = $request->validate([
            'empleado_id' => ['required', 'uuid'],
            'puntualidad' => ['required', 'integer', 'min:1', 'max:5'],
            'responsabilidad' => ['required', 'integer', 'min:1', 'max:5'],
            'actitud_trabajo' => ['required', 'integer', 'min:1', 'max:5'],
            'orden_limpieza' => ['required', 'integer', 'min:1', 'max:5'],
            'atencion_cliente' => ['required', 'integer', 'min:1', 'max:5'],
            'trabajo_equipo' => ['required', 'integer', 'min:1', 'max:5'],
            'iniciativa' => ['required', 'integer', 'min:1', 'max:5'],
            'aprendizaje_adaptacion' => ['required', 'integer', 'min:1', 'max:5'],
            'acciones' => ['nullable', 'array'],
            'acciones.*' => ['string', 'in:mantener_desempeno,capacitacion,llamada_atencion,seguimiento_30_dias'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ]);

        // Verificar evaluación activa
        $evaluation = EmployeeEvaluation::where('empresa_id', $u->empresa_id)
            ->where('empleado_id', $data['empleado_id'])
            ->where('is_active', true)
            ->first();

        if (! $evaluation) {
            return response()->json([
                'message' => 'No hay evaluación activa para este empleado',
            ], 404);
        }

        // Verificar que no haya evaluado ya
        $alreadyEvaluated = DesempenoEvaluacion::where('employee_evaluation_id', $evaluation->id)
            ->where('evaluador_id', $u->id)
            ->exists();

        if ($alreadyEvaluated) {
            return response()->json([
                'message' => 'Ya evaluaste a este empleado',
            ], 409);
        }

        $eval = DesempenoEvaluacion::create([
            'empresa_id' => $u->empresa_id,
            'employee_evaluation_id' => $evaluation->id,
            'evaluador_id' => $u->id,
            'evaluado_id' => $data['empleado_id'],
            'evaluador_rol' => $u->role,
            'puntualidad' => $data['puntualidad'],
            'responsabilidad' => $data['responsabilidad'],
            'actitud_trabajo' => $data['actitud_trabajo'],
            'orden_limpieza' => $data['orden_limpieza'],
            'atencion_cliente' => $data['atencion_cliente'],
            'trabajo_equipo' => $data['trabajo_equipo'],
            'iniciativa' => $data['iniciativa'],
            'aprendizaje_adaptacion' => $data['aprendizaje_adaptacion'],
            'acciones' => $data['acciones'] ?? null,
            'observaciones' => $data['observaciones'] ?? null,
        ]);

        return response()->json([
            'message' => 'Evaluación registrada',
            'evaluacion' => [
                'id' => $eval->id,
                'total' => $eval->total,
                'porcentaje' => $eval->porcentaje,
            ],
        ], 201);
    }

    /**
     * GET /semaforo/mis-evaluaciones-pendientes
     * Supervisor ve qué empleados puede evaluar.
     */
    public function pendientesSupervisor(Request $request)
    {
        Gate::authorize('supervisor');

        $u = $request->user();
        // Evaluaciones activas de la empresa
        $activeEvaluations = EmployeeEvaluation::where('empresa_id', $u->empresa_id)
            ->where('is_active', true)
            ->with(['empleado', 'evaluaciones', 'peerEvaluaciones'])
            ->get();

        // Filtrar los que el usuario NO ha evaluado aún
        $pendientes = $activeEvaluations->filter(function ($eval) use ($u) {
            return ! $eval->evaluaciones->contains('evaluador_id', $u->id);
        });

        $result = $pendientes->values()->map(function ($eval) {
            $scores = $this->calcularResultado($eval);

            return [
                'empleado' => new EmpleadoResource($eval->empleado),
                'evaluation' => [
                    'id' => $eval->id,
                    'is_active' => $eval->is_active,
                    'activated_at' => $eval->activated_at,
                    'evaluaciones_count' => $eval->evaluaciones->count(),
                    'peer_evaluaciones_count' => $eval->peerEvaluaciones->count(),
                    'semaforo' => $scores['semaforo'],
                ],
            ];
        });

        return response()->json($result);
    }

    // ─── EMPLEADO ENDPOINTS ───────────────────────────────────────────────────

    /**
     * GET /semaforo/companeros
     * Empleado ve a quién puede evaluar.
     */
    public function companeros(Request $request)
    {
        $u = $request->user();
        if ($u->role !== 'empleado') {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        // Evaluaciones activas de la empresa (excluyendo al propio usuario)
        $activeEvaluations = EmployeeEvaluation::where('empresa_id', $u->empresa_id)
            ->where('is_active', true)
            ->with('empleado')
            ->get();

        // Obtener el empleado_id del usuario actual
        $miEmpleado = Empleado::where('user_id', $u->id)
            ->where('empresa_id', $u->empresa_id)
            ->first();

        // Excluir al propio usuario
        $companeros = $activeEvaluations->filter(function ($eval) use ($miEmpleado) {
            return ! $miEmpleado || $eval->empleado_id !== $miEmpleado->id;
        });

        $evaluated = 0;
        $total = $companeros->count();

        $result = $companeros->values()->map(function ($eval) use ($u, &$evaluated) {
            $alreadyEvaluated = DesempenoPeerEvaluacion::where('employee_evaluation_id', $eval->id)
                ->where('evaluador_id', $u->id)
                ->exists();

            if ($alreadyEvaluated) {
                $evaluated++;
            }

            return [
                'id' => $eval->empleado->id,
                'nombre' => $eval->empleado->full_name,
                'position_title' => $eval->empleado->position_title,
                'avatar_url' => $eval->empleado->expediente_url,
                'already_evaluated' => $alreadyEvaluated,
            ];
        });

        return response()->json([
            'companeros' => $result,
            'progress' => [
                'evaluados' => $evaluated,
                'total' => $total,
            ],
        ]);
    }

    /**
     * POST /semaforo/peer-evaluaciones
     * Empleado evalúa a un compañero.
     */
    public function peerEvaluar(Request $request)
    {
        $u = $request->user();
        if ($u->role !== 'empleado') {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $data = $request->validate([
            'employee_evaluation_id' => ['required', 'uuid'],
            'evaluado_empleado_id' => ['required', 'uuid'],
            'colaboracion' => ['required', 'integer', 'min:1', 'max:5'],
            'puntualidad' => ['required', 'integer', 'min:1', 'max:5'],
            'actitud' => ['required', 'integer', 'min:1', 'max:5'],
            'comunicacion' => ['required', 'integer', 'min:1', 'max:5'],
        ]);

        // Verificar que la evaluación existe y está activa
        $evaluation = EmployeeEvaluation::where('id', $data['employee_evaluation_id'])
            ->where('empresa_id', $u->empresa_id)
            ->where('is_active', true)
            ->first();

        if (! $evaluation) {
            return response()->json([
                'message' => 'No hay evaluación activa para este empleado',
            ], 404);
        }

        // Verificar que el evaluado pertenece a la misma empresa
        $evaluado = Empleado::where('id', $data['evaluado_empleado_id'])
            ->where('empresa_id', $u->empresa_id)
            ->first();

        if (! $evaluado) {
            return response()->json([
                'message' => 'El empleado evaluado no existe en tu empresa',
            ], 404);
        }

        // No puede evaluarse a sí mismo
        $miEmpleado = Empleado::where('user_id', $u->id)
            ->where('empresa_id', $u->empresa_id)
            ->first();

        if ($miEmpleado && $miEmpleado->id === $data['evaluado_empleado_id']) {
            return response()->json([
                'message' => 'No puedes evaluarte a ti mismo',
            ], 422);
        }

        // Verificar que no haya evaluado ya en esta activación
        $alreadyEvaluated = DesempenoPeerEvaluacion::where('employee_evaluation_id', $evaluation->id)
            ->where('evaluador_id', $u->id)
            ->exists();

        if ($alreadyEvaluated) {
            return response()->json([
                'message' => 'Ya evaluaste a este compañero',
            ], 409);
        }

        $peer = DesempenoPeerEvaluacion::create([
            'empresa_id' => $u->empresa_id,
            'employee_evaluation_id' => $evaluation->id,
            'evaluador_id' => $u->id,
            'evaluado_id' => $data['evaluado_empleado_id'],
            'colaboracion' => $data['colaboracion'],
            'puntualidad' => $data['puntualidad'],
            'actitud' => $data['actitud'],
            'comunicacion' => $data['comunicacion'],
        ]);

        // NUNCA devolver evaluador_id en respuestas para empleados
        return response()->json([
            'message' => 'Evaluación de compañero registrada',
            'peer' => [
                'id' => $peer->id,
                'promedio' => $peer->promedio,
                'porcentaje' => $peer->porcentaje,
            ],
        ], 201);
    }

    // ─── CONFIGURACIÓN SEMÁFORO ───────────────────────────────────────────────

    private function defaultSemaforoConfig(): array
    {
        return [
            'criterios_admin' => [
                ['key' => 'puntualidad', 'label' => 'Puntualidad'],
                ['key' => 'asistencia', 'label' => 'Asistencia'],
                ['key' => 'productividad', 'label' => 'Productividad'],
                ['key' => 'calidad', 'label' => 'Calidad de trabajo'],
                ['key' => 'disciplina', 'label' => 'Disciplina'],
                ['key' => 'proactividad', 'label' => 'Proactividad'],
                ['key' => 'trabajo_equipo', 'label' => 'Trabajo en equipo'],
                ['key' => 'cumplimiento', 'label' => 'Cumplimiento de normas'],
            ],
            'criterios_peer' => [
                ['key' => 'cooperacion', 'label' => 'Cooperación', 'icon' => 'Handshake'],
                ['key' => 'comunicacion', 'label' => 'Comunicación', 'icon' => 'MessageCircle'],
                ['key' => 'responsabilidad', 'label' => 'Responsabilidad', 'icon' => 'Shield'],
                ['key' => 'apoyo', 'label' => 'Apoyo al equipo', 'icon' => 'Heart'],
            ],
            'peso_admin' => 70,
            'peso_peer' => 30,
            'umbral_verde' => 80,
            'umbral_amarillo' => 60,
        ];
    }

    /**
     * GET /semaforo/config
     * Cualquier usuario autenticado puede leer.
     */
    public function configShow(Request $request)
    {
        $u = $request->user();
        $config = SemaforoConfig::where('empresa_id', $u->empresa_id)->first();

        if (! $config) {
            return response()->json($this->defaultSemaforoConfig());
        }

        return response()->json([
            'criterios_admin' => $config->criterios_admin,
            'criterios_peer' => $config->criterios_peer,
            'peso_admin' => $config->peso_admin,
            'peso_peer' => $config->peso_peer,
            'umbral_verde' => $config->umbral_verde,
            'umbral_amarillo' => $config->umbral_amarillo,
            'updated_at' => $config->updated_at,
        ]);
    }

    /**
     * POST /semaforo/config
     * Solo admin/superadmin pueden guardar.
     */
    public function configStore(Request $request)
    {
        $u = $request->user();
        if (! in_array($u->role, ['admin', 'superadmin'])) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $validator = Validator::make($request->all(), [
            'criterios_admin' => ['required', 'array', 'min:1'],
            'criterios_admin.*.key' => ['required', 'string', 'regex:/^[a-z0-9_]+$/', 'max:50'],
            'criterios_admin.*.label' => ['required', 'string', 'max:50'],
            'criterios_peer' => ['required', 'array', 'min:1'],
            'criterios_peer.*.key' => ['required', 'string', 'regex:/^[a-z0-9_]+$/', 'max:50'],
            'criterios_peer.*.label' => ['required', 'string', 'max:50'],
            'criterios_peer.*.icon' => ['required', 'string', 'max:50'],
            'peso_admin' => ['required', 'integer', 'min:0', 'max:100'],
            'peso_peer' => ['required', 'integer', 'min:0', 'max:100'],
            'umbral_verde' => ['required', 'integer', 'min:0', 'max:100'],
            'umbral_amarillo' => ['required', 'integer', 'min:0', 'max:100'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        if ($data['peso_admin'] + $data['peso_peer'] !== 100) {
            return response()->json([
                'message' => 'Los pesos deben sumar 100',
                'errors' => ['peso_admin' => ['peso_admin + peso_peer debe sumar 100']],
            ], 422);
        }

        if ($data['umbral_verde'] <= $data['umbral_amarillo']) {
            return response()->json([
                'message' => 'El umbral verde debe ser mayor que el umbral amarillo',
                'errors' => ['umbral_verde' => ['umbral_verde debe ser mayor que umbral_amarillo']],
            ], 422);
        }

        $config = SemaforoConfig::updateOrCreate(
            ['empresa_id' => $u->empresa_id],
            [
                'created_by' => $u->id,
                'criterios_admin' => $data['criterios_admin'],
                'criterios_peer' => $data['criterios_peer'],
                'peso_admin' => $data['peso_admin'],
                'peso_peer' => $data['peso_peer'],
                'umbral_verde' => $data['umbral_verde'],
                'umbral_amarillo' => $data['umbral_amarillo'],
            ]
        );

        return response()->json([
            'message' => 'Configuración guardada correctamente',
            'config' => [
                'criterios_admin' => $config->criterios_admin,
                'criterios_peer' => $config->criterios_peer,
                'peso_admin' => $config->peso_admin,
                'peso_peer' => $config->peso_peer,
                'umbral_verde' => $config->umbral_verde,
                'umbral_amarillo' => $config->umbral_amarillo,
                'updated_at' => $config->updated_at,
            ],
        ]);
    }
}

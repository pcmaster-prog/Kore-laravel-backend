<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Models\Gondola;
use App\Models\GondolaOrden;
use App\Models\GondolaOrdenItem;
use App\Models\Task;
use App\Models\Empleado;
use App\Services\TaskService;
use App\Services\NotificationService;
use App\Http\Resources\GondolaOrdenResource;

class GondolaOrdenesController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // ENDPOINTS GESTIÓN (admin / supervisor)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * GET /gondola-ordenes
     * Lista órdenes con filtros opcionales y paginación de 20.
     * Acceso: admin / supervisor
     */
    public function index(Request $request)
    {
        $user = $request->user();

        if (!in_array($user->role, ['admin', 'supervisor'])) {
            return response()->json(['message' => 'Acceso denegado.'], 403);
        }

        $query = GondolaOrden::where('empresa_id', $user->empresa_id)
            ->with(['gondola:id,nombre', 'empleado:id,full_name,position_title', 'items.producto', 'items.product', 'approvedBy:id,name'])
            ->orderBy('created_at', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('gondola_id')) {
            $query->where('gondola_id', $request->gondola_id);
        }

        if ($request->filled('empleado_id')) {
            $query->where('empleado_id', $request->empleado_id);
        }

        if ($request->filled('fecha')) {
            $query->whereDate('created_at', $request->fecha);
        }

        $ordenes = $query->paginate(20);

        return GondolaOrdenResource::collection($ordenes);
    }

    /**
     * POST /gondola-ordenes
     * Crear orden y copiar snapshot de todos los productos activos de la góndola.
     * Acceso: admin / supervisor
     */
    public function store(Request $request)
    {
        $user = $request->user();

        if (!in_array($user->role, ['admin', 'supervisor'])) {
            return response()->json(['message' => 'Acceso denegado.'], 403);
        }

        $data = $request->validate([
            'gondola_id'  => ['required', 'uuid'],
            'empleado_id' => ['required', 'uuid'],
            'notas'       => ['nullable', 'string', 'max:500'],
        ]);

        $gondola = Gondola::where('empresa_id', $user->empresa_id)
            ->where('activo', true)
            ->findOrFail($data['gondola_id']);

        $empresaId = $user->empresa_id;

        // Section 2.4: operación atómica (orden + snapshot de items)
        $orden = DB::transaction(function () use ($empresaId, $gondola, $data) {
            $orden = GondolaOrden::create([
                'empresa_id'     => $empresaId,
                'gondola_id'     => $gondola->id,
                'empleado_id'    => $data['empleado_id'],
                'status'         => 'pendiente',
                'notas_empleado' => $data['notas'] ?? null,
            ]);

            // Crear snapshot de todos los productos activos de la góndola
            foreach ($gondola->productos as $producto) {
                GondolaOrdenItem::create([
                    'empresa_id'          => $empresaId,
                    'orden_id'            => $orden->id,
                    'gondola_producto_id' => $producto->id,
                    'product_id'          => $producto->product_id,
                    'clave'               => $producto->clave,
                    'nombre'              => $producto->nombre,
                    'unidad'              => $producto->unidad,
                    'cantidad'            => null, // el empleado lo llenará
                ]);
            }

            return $orden;
        });

        return (new GondolaOrdenResource($orden->fresh()))->response()->setStatusCode(201);
    }

    /**
     * GET /gondola-ordenes/{id}
     * Detalle completo con items y foto_url de cada producto.
     */
    public function show(Request $request, string $id)
    {
        $orden = GondolaOrden::where('empresa_id', $request->user()->empresa_id)
            ->with(['items.producto', 'items.product'])
            ->findOrFail($id);

        return new GondolaOrdenResource($orden);
    }

    /**
     * POST /gondola-ordenes/{id}/aprobar
     * Admin/supervisor aprueba la orden (debe estar en status 'completado').
     */
    public function aprobar(Request $request, string $id)
    {
        $user = $request->user();

        if (!in_array($user->role, ['admin', 'supervisor'])) {
            return response()->json(['message' => 'Acceso denegado.'], 403);
        }

        $orden = GondolaOrden::where('empresa_id', $user->empresa_id)->findOrFail($id);

        if ($orden->status !== 'completado') {
            return response()->json(['message' => 'Solo se pueden aprobar órdenes con status "completado".'], 422);
        }

        $orden->update([
            'status'      => 'aprobado',
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);

        if ($orden->task) {
            $orden->task->status = 'completed';
            $orden->task->save();
        }

        return new GondolaOrdenResource($orden->fresh());
    }

    /**
     * POST /gondola-ordenes/{id}/rechazar
     * Admin/supervisor rechaza la orden. Regresa a 'en_proceso' para que el empleado complete de nuevo.
     */
    public function rechazar(Request $request, string $id)
    {
        $user = $request->user();

        if (!in_array($user->role, ['admin', 'supervisor'])) {
            return response()->json(['message' => 'Acceso denegado.'], 403);
        }

        $orden = GondolaOrden::where('empresa_id', $user->empresa_id)->findOrFail($id);

        if ($orden->status !== 'completado') {
            return response()->json(['message' => 'Solo se pueden rechazar órdenes con status "completado".'], 422);
        }

        $data = $request->validate([
            'notas_rechazo' => ['required', 'string', 'max:500'],
        ]);

        $orden->update([
            // Regresa a en_proceso para que el empleado pueda volver a completar
            'status'        => 'en_proceso',
            'notas_rechazo' => $data['notas_rechazo'],
            'completed_at'  => null,
        ]);

        if ($orden->task) {
            $orden->task->status = 'in_progress';
            $orden->task->save();
        }

        return new GondolaOrdenResource($orden->fresh());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ENDPOINTS EMPLEADO
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * POST /gondola-ordenes/{id}/iniciar
     * El empleado asignado inicia la orden (pendiente → en_proceso).
     */
    public function iniciar(Request $request, string $id)
    {
        $user = $request->user();

        $orden = GondolaOrden::where('empresa_id', $user->empresa_id)->findOrFail($id);

        // Verificar que el empleado sea el asignado
        if ($orden->empleado_id !== ($user->empleado?->id ?? null)) {
            return response()->json(['message' => 'Solo el empleado asignado puede iniciar esta orden.'], 403);
        }

        if ($orden->status !== 'pendiente') {
            return response()->json(['message' => 'La orden ya fue iniciada o no está pendiente.'], 422);
        }

        $orden->update(['status' => 'en_proceso']);

        return new GondolaOrdenResource($orden->fresh());
    }

    /**
     * POST /gondola-ordenes/{id}/completar
     * El empleado registra cantidades + evidencia y marca como completado.
     *
     * Body:
     * {
     *   "items": [{ "id": "uuid", "cantidad": 3.5, "unit": "kg" }, ...],
     *   "notas_empleado": "opcional",
     *   "evidencia_url": "url_ya_subida" (opcional si se sube como multipart)
     * }
     *
     * También acepta evidencia como archivo (multipart key: "evidencia").
     */
    public function completar(Request $request, string $id)
    {
        $user = $request->user();

        $orden = GondolaOrden::where('empresa_id', $user->empresa_id)
            ->with('items')
            ->findOrFail($id);

        // Verificar que el empleado sea el asignado
        if ($orden->empleado_id !== ($user->empleado?->id ?? null)) {
            return response()->json(['message' => 'Solo el empleado asignado puede completar esta orden.'], 403);
        }

        if (!in_array($orden->status, ['pendiente', 'en_proceso'])) {
            return response()->json(['message' => 'La orden no está en un estado completable.'], 422);
        }

        if (is_string($request->input('items'))) {
            $request->merge([
                'items' => json_decode($request->input('items'), true)
            ]);
        }

        $data = $request->validate([
            'items'                => ['required', 'array', 'min:1'],
            'items.*.id'           => ['required', 'uuid'],
            'items.*.cantidad'     => ['required', 'numeric', 'min:0'],
            'items.*.unit'         => ['nullable', 'string', 'max:40'],
            'notas_empleado'       => ['nullable', 'string', 'max:500'],
            'evidencia_url'        => ['nullable', 'string', 'max:500'],
            'evidencia'            => ['nullable', 'file', 'image', 'max:5120'],
        ]);

        // Indexar items actuales por id para obtener el snapshot de unidad
        $itemsSnapshot = $orden->items->keyBy('id');

        // Section 2.4: actualización atómica (items + status + evidencia)
        DB::transaction(function () use ($orden, $data, $request, $user, $itemsSnapshot) {
            // Actualizar cantidades y unidad de cada item
            foreach ($data['items'] as $itemData) {
                $item = $itemsSnapshot->get($itemData['id']);
                $unit = $itemData['unit'] ?? ($item?->unidad ?? null);

                GondolaOrdenItem::where('orden_id', $orden->id)
                    ->where('id', $itemData['id'])
                    ->update([
                        'cantidad' => $itemData['cantidad'],
                        'unit'     => $unit,
                    ]);
            }

            // Subir evidencia si viene como archivo
            $evidenciaUrl = $data['evidencia_url'] ?? $orden->evidencia_url;

            if ($request->hasFile('evidencia')) {
                $path = $request->file('evidencia')->store(
                    "kore/{$user->empresa_id}/gondola-ordenes/{$orden->id}",
                    's3'
                );

                // Almacenamos el path, y generamos la URL temporal al consultarlo
                $evidenciaUrl = $path;
            }

            $orden->update([
                'status'         => 'completado',
                'notas_empleado' => $data['notas_empleado'] ?? $orden->notas_empleado,
                'evidencia_url'  => $evidenciaUrl,
                'completed_at'   => now(),
            ]);

            if ($orden->task) {
                $orden->task->status = 'done_pending';
                $orden->task->save();

                // Notificar a managers
                try {
                    app(NotificationService::class)->sendToManagers(
                        empresaId: $user->empresa_id,
                        title: '✅ Orden de góndola lista para revisar',
                        body: "La orden de {$orden->gondola?->nombre} ha sido completada",
                        data: [
                            'type'     => 'gondola.done_pending',
                            'orden_id' => $orden->id,
                            'task_id'  => $orden->task->id,
                        ]
                    );
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('Error notifying gondola done_pending: ' . $e->getMessage());
                }
            }
        });

        return new GondolaOrdenResource($orden->fresh());
    }

    /**
     * GET /mis-ordenes-gondola
     * Órdenes activas del empleado autenticado (pendiente, en_proceso, rechazado).
     * Incluye foto_url en cada item como referencia visual.
     */
    public function misOrdenes(Request $request)
    {
        $user = $request->user();

        $empleadoId = $user->empleado?->id;

        if (!$empleadoId) {
            return response()->json(['message' => 'No se encontró el empleado asociado a este usuario.'], 404);
        }

        $ordenes = GondolaOrden::where('empresa_id', $user->empresa_id)
            ->where('empleado_id', $empleadoId)
            ->whereIn('status', ['pendiente', 'en_proceso', 'rechazado'])
            ->with([
                'gondola:id,nombre,ubicacion',
                'items.producto', // para obtener foto_url
                'items.product',  // datos del producto maestro
            ])
            ->orderBy('created_at', 'desc')
            ->get();

        return GondolaOrdenResource::collection($ordenes);
    }

    /**
     * POST /gondolas/{gondolaId}/auto-rellenar
     * El empleado inicia un relleno por iniciativa propia sin esperar orden.
     */
    public function autoRellenar(Request $request, string $gondolaId)
    {
        $u = $request->user();

        $emp = Empleado::where('empresa_id', $u->empresa_id)
            ->where('user_id', $u->id)
            ->first();

        if (!$emp) {
            return response()->json(['message' => 'Empleado no vinculado'], 404);
        }

        $gondola = Gondola::where('empresa_id', $u->empresa_id)
            ->where('id', $gondolaId)
            ->where('activo', true)
            ->firstOrFail();

        // Verificar que no haya ya una orden activa de este empleado para esta góndola
        $existing = GondolaOrden::where('empresa_id', $u->empresa_id)
            ->where('gondola_id', $gondolaId)
            ->where('empleado_id', $emp->id)
            ->whereIn('status', ['pendiente', 'en_proceso'])
            ->first();

        if ($existing) {
            return response()->json([
                'message'  => 'Ya tienes una orden activa para esta góndola',
                'orden_id' => $existing->id,
            ], 409);
        }

        $orden = DB::transaction(function () use ($u, $gondolaId, $emp, $gondola) {
            $orden = GondolaOrden::create([
                'empresa_id'     => $u->empresa_id,
                'gondola_id'     => $gondolaId,
                'empleado_id'    => $emp->id,
                'status'         => 'en_proceso',
                'notas_empleado' => 'Iniciado por iniciativa propia',
            ]);

            // Copiar snapshot de productos
            foreach ($gondola->productos as $producto) {
                GondolaOrdenItem::create([
                    'empresa_id'          => $u->empresa_id,
                    'orden_id'            => $orden->id,
                    'gondola_producto_id' => $producto->id,
                    'product_id'          => $producto->product_id,
                    'clave'               => $producto->clave,
                    'nombre'              => $producto->nombre,
                    'unidad'              => $producto->unidad,
                    'cantidad'            => null,
                ]);
            }

            return $orden;
        });

        // Notificar a admins y supervisores
        try {
            app(NotificationService::class)->sendToManagers(
                empresaId: $u->empresa_id,
                title: '🛒 Relleno iniciado por iniciativa',
                body: "{$emp->full_name} inició el relleno de {$gondola->nombre}",
                data: [
                    'type'     => 'gondola.auto_started',
                    'orden_id' => $orden->id,
                    'gondola'  => $gondola->nombre,
                    'empleado' => $emp->full_name,
                ]
            );
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error notifying gondola auto-start: ' . $e->getMessage());
        }

        return response()->json([
            'message'  => 'Relleno iniciado',
            'orden_id' => $orden->id,
        ], 201);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GENERAR TAREA DE RELLENO
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * POST /gondolas/{id}/generar-tarea
     * Admin/Supervisor genera una tarea de relleno para una góndola.
     */
    public function generarTarea(Request $request, string $id)
    {
        $user = $request->user();

        if (!in_array($user->role, ['admin', 'supervisor'])) {
            return response()->json(['message' => 'Acceso denegado.'], 403);
        }

        $data = $request->validate([
            'empleado_ids' => ['required', 'array', 'min:1'],
            'empleado_ids.*' => ['required', 'uuid'],
            'due_at'       => ['nullable', 'date'],
            'notas'        => ['nullable', 'string', 'max:500'],
        ]);

        $gondola = Gondola::where('empresa_id', $user->empresa_id)
            ->where('id', $id)
            ->where('activo', true)
            ->firstOrFail();

        $empresaId = $user->empresa_id;
        $primerEmpleadoId = $data['empleado_ids'][0];

        $result = DB::transaction(function () use ($empresaId, $user, $gondola, $primerEmpleadoId, $data) {
            // 1. Crear orden pendiente para el primer empleado
            $orden = GondolaOrden::create([
                'empresa_id'     => $empresaId,
                'gondola_id'     => $gondola->id,
                'empleado_id'    => $primerEmpleadoId,
                'status'         => 'pendiente',
                'notas_empleado' => $data['notas'] ?? null,
            ]);

            // 2. Crear items copiando productos activos de la góndola
            foreach ($gondola->productos as $producto) {
                GondolaOrdenItem::create([
                    'empresa_id'          => $empresaId,
                    'orden_id'            => $orden->id,
                    'gondola_producto_id' => $producto->id,
                    'product_id'          => $producto->product_id,
                    'clave'               => $producto->clave,
                    'nombre'              => $producto->nombre,
                    'unidad'              => $producto->unidad,
                    'cantidad'            => null,
                ]);
            }

            // 3. Crear Task vinculada a la orden
            $task = Task::create([
                'empresa_id'       => $empresaId,
                'created_by'       => $user->id,
                'title'            => "Rellenar: {$gondola->nombre}",
                'description'      => $data['notas'] ?? "Orden de relleno para {$gondola->nombre}",
                'priority'         => 'medium',
                'status'           => 'open',
                'due_at'           => $data['due_at'] ?? null,
                'gondola_orden_id' => $orden->id,
                'task_source'      => 'gondola_refill',
                'meta'             => [
                    'gondola_id'   => $gondola->id,
                    'gondola_name' => $gondola->nombre,
                    'template_id'  => null,
                    'catalog_date' => now()->toDateString(),
                ],
            ]);

            // 4. Asignar tarea a empleados
            $assignResult = TaskService::assignTask($task, $data['empleado_ids'], $user);

            if (!$assignResult['success']) {
                // Rollback implícito por excepción
                abort($assignResult['code'] ?? 422, $assignResult['message']);
            }

            return ['task' => $task, 'orden' => $orden];
        });

        // 5. Notificar push a los empleados asignados
        try {
            $empleados = Empleado::where('empresa_id', $empresaId)
                ->whereIn('id', $data['empleado_ids'])
                ->with('user')
                ->get();

            foreach ($empleados as $emp) {
                if ($emp->user_id) {
                    app(NotificationService::class)->sendToUser(
                        userId: $emp->user_id,
                        title: '📦 Nueva tarea de relleno',
                        body: "Se te asignó el relleno de {$gondola->nombre}",
                        data: [
                            'type'     => 'gondola.task_assigned',
                            'task_id'  => $result['task']->id,
                            'orden_id' => $result['orden']->id,
                        ]
                    );
                }
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error notifying gondola task assignees: ' . $e->getMessage());
        }

        return response()->json([
            'task'  => $result['task']->fresh(['assignees.empleado']),
            'orden' => new GondolaOrdenResource($result['orden']->fresh(['items.product', 'items.producto'])),
        ], 201);
    }
}

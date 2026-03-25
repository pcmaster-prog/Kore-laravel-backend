<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Models\Gondola;
use App\Models\GondolaOrden;
use App\Models\GondolaOrdenItem;

class GondolaOrdenesController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // Helpers de respuesta
    // ─────────────────────────────────────────────────────────────────────────

    private function formatOrden(GondolaOrden $orden): array
    {
        $orden->load(['gondola:id,nombre', 'empleado:id,full_name,position_title', 'items.producto', 'approvedBy:id,name']);

        return [
            'id'             => $orden->id,
            'gondola'        => $orden->gondola ? [
                'id'     => $orden->gondola->id,
                'nombre' => $orden->gondola->nombre,
            ] : null,
            'empleado'       => $orden->empleado ? [
                'id'             => $orden->empleado->id,
                'full_name'      => $orden->empleado->full_name,
                'position_title' => $orden->empleado->position_title,
            ] : null,
            'status'          => $orden->status,
            'notas_empleado'  => $orden->notas_empleado,
            'notas_rechazo'   => $orden->notas_rechazo,
            'evidencia_url'   => $orden->evidencia_url,
            'completed_at'    => $orden->completed_at,
            'approved_at'     => $orden->approved_at,
            'approved_by'     => $orden->approvedBy ? $orden->approvedBy->name : null,
            'created_at'      => $orden->created_at,
            'items'           => $orden->items->map(fn ($item) => [
                'id'                  => $item->id,
                'gondola_producto_id' => $item->gondola_producto_id,
                'clave'               => $item->clave,
                'nombre'              => $item->nombre,
                'unidad'              => $item->unidad,
                'cantidad'            => $item->cantidad,
                // foto_url del producto original (referencia visual para el empleado)
                'foto_url'            => $item->producto?->foto_url,
            ])->values(),
        ];
    }

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
            ->with(['gondola:id,nombre', 'empleado:id,full_name'])
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

        return response()->json($ordenes);
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

        $orden = GondolaOrden::create([
            'empresa_id'  => $empresaId,
            'gondola_id'  => $gondola->id,
            'empleado_id' => $data['empleado_id'],
            'status'      => 'pendiente',
            'notas_empleado' => $data['notas'] ?? null,
        ]);

        // Crear snapshot de todos los productos activos de la góndola
        foreach ($gondola->productos as $producto) {
            GondolaOrdenItem::create([
                'empresa_id'          => $empresaId,
                'orden_id'            => $orden->id,
                'gondola_producto_id' => $producto->id,
                'clave'               => $producto->clave,
                'nombre'              => $producto->nombre,
                'unidad'              => $producto->unidad,
                'cantidad'            => null, // el empleado lo llenará
            ]);
        }

        return response()->json($this->formatOrden($orden->fresh()), 201);
    }

    /**
     * GET /gondola-ordenes/{id}
     * Detalle completo con items y foto_url de cada producto.
     */
    public function show(Request $request, string $id)
    {
        $orden = GondolaOrden::where('empresa_id', $request->user()->empresa_id)
            ->findOrFail($id);

        return response()->json($this->formatOrden($orden));
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

        return response()->json($this->formatOrden($orden->fresh()));
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

        return response()->json($this->formatOrden($orden->fresh()));
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

        return response()->json($this->formatOrden($orden->fresh()));
    }

    /**
     * POST /gondola-ordenes/{id}/completar
     * El empleado registra cantidades + evidencia y marca como completado.
     *
     * Body:
     * {
     *   "items": [{ "id": "uuid", "cantidad": 3.5 }, ...],
     *   "notas_empleado": "opcional",
     *   "evidencia_url": "url_ya_subida" (opcional si se sube como multipart)
     * }
     *
     * También acepta evidencia como archivo (multipart key: "evidencia").
     */
    public function completar(Request $request, string $id)
    {
        $user = $request->user();

        $orden = GondolaOrden::where('empresa_id', $user->empresa_id)->findOrFail($id);

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
            'notas_empleado'       => ['nullable', 'string', 'max:500'],
            'evidencia_url'        => ['nullable', 'string', 'max:500'],
            'evidencia'            => ['nullable', 'file', 'image', 'max:5120'],
        ]);

        // Actualizar cantidades de cada item
        foreach ($data['items'] as $itemData) {
            GondolaOrdenItem::where('orden_id', $orden->id)
                ->where('id', $itemData['id'])
                ->update(['cantidad' => $itemData['cantidad']]);
        }

        // Subir evidencia si viene como archivo
        $evidenciaUrl = $data['evidencia_url'] ?? $orden->evidencia_url;

        if ($request->hasFile('evidencia')) {
            $path = $request->file('evidencia')->store(
                "kore/{$user->empresa_id}/gondola-ordenes/{$orden->id}",
                's3'
            );

            // PENDIENTE: Requiere bucket público en Backblaze B2.
            // Cuando se active el acceso público funcionará automáticamente sin cambios.
            $evidenciaUrl = Storage::disk('s3')->url($path);
        }

        $orden->update([
            'status'         => 'completado',
            'notas_empleado' => $data['notas_empleado'] ?? $orden->notas_empleado,
            'evidencia_url'  => $evidenciaUrl,
            'completed_at'   => now(),
        ]);

        return response()->json($this->formatOrden($orden->fresh()));
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
            ])
            ->orderBy('created_at', 'desc')
            ->get();

        $result = $ordenes->map(fn ($orden) => [
            'id'            => $orden->id,
            'status'        => $orden->status,
            'notas_rechazo' => $orden->notas_rechazo,
            'created_at'    => $orden->created_at,
            'gondola'       => $orden->gondola ? [
                'id'       => $orden->gondola->id,
                'nombre'   => $orden->gondola->nombre,
                'ubicacion'=> $orden->gondola->ubicacion,
            ] : null,
            // Items con foto_url del producto original como referencia visual
            'items' => $orden->items->map(fn ($item) => [
                'id'                  => $item->id,
                'gondola_producto_id' => $item->gondola_producto_id,
                'clave'               => $item->clave,
                'nombre'              => $item->nombre,
                'unidad'              => $item->unidad,
                'cantidad'            => $item->cantidad,
                'foto_url'            => $item->producto?->foto_url,
            ])->values(),
        ]);

        return response()->json($result);
    }
}

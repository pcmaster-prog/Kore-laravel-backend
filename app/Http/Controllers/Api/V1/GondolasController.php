<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Models\Gondola;
use App\Models\GondolaProducto;

class GondolasController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // GÓNDOLAS CRUD
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * GET /gondolas
     * Lista góndolas activas de la empresa con contadores.
     * Acceso: admin / supervisor
     */
    public function index(Request $request)
    {
        $user      = $request->user();
        $empresaId = $user->empresa_id;

        if (!in_array($user->role, ['admin', 'supervisor'])) {
            return response()->json(['message' => 'Acceso denegado.'], 403);
        }

        $gondolas = Gondola::where('empresa_id', $empresaId)
            ->where('activo', true)
            ->withCount([
                'productos as productos_count' => fn ($q) => $q->where('activo', true),
                'ordenes as ordenes_pendientes' => fn ($q) => $q->where('status', 'pendiente')
            ])
            ->with(['ultimaOrden' => fn ($q) => $q->select('id', 'gondola_id', 'created_at', 'status')])
            ->orderBy('orden')
            ->get();

        return response()->json($gondolas->map(fn ($g) => [
            'id'                => $g->id,
            'nombre'            => $g->nombre,
            'descripcion'       => $g->descripcion,
            'ubicacion'         => $g->ubicacion,
            'orden'             => $g->orden,
            'activo'            => $g->activo,
            'productos_count'   => $g->productos_count,
            'ordenes_pendientes'=> $g->ordenes_pendientes,
            'ultima_orden'      => $g->ultimaOrden ? [
                'created_at' => $g->ultimaOrden->created_at,
                'status'     => $g->ultimaOrden->status,
            ] : null,
        ]));
    }

    /**
     * POST /gondolas
     * Crear góndola.
     * Acceso: admin / supervisor
     */
    public function store(Request $request)
    {
        $user = $request->user();

        if (!in_array($user->role, ['admin', 'supervisor'])) {
            return response()->json(['message' => 'Acceso denegado.'], 403);
        }

        $data = $request->validate([
            'nombre'      => ['required', 'string', 'max:100'],
            'descripcion' => ['nullable', 'string', 'max:300'],
            'ubicacion'   => ['nullable', 'string', 'max:100'],
            'orden'       => ['nullable', 'integer'],
        ]);

        $gondola = Gondola::create([
            'empresa_id'  => $user->empresa_id,
            'nombre'      => $data['nombre'],
            'descripcion' => $data['descripcion'] ?? null,
            'ubicacion'   => $data['ubicacion'] ?? null,
            'orden'       => $data['orden'] ?? 0,
            'activo'      => true,
        ]);

        return response()->json($gondola, 201);
    }

    /**
     * GET /gondolas/{id}
     * Detalle con productos activos.
     */
    public function show(Request $request, string $id)
    {
        $gondola = Gondola::where('empresa_id', $request->user()->empresa_id)
            ->where('activo', true)
            ->with(['productos' => fn ($q) => $q->orderBy('orden')])
            ->findOrFail($id);

        return response()->json($gondola);
    }

    /**
     * PATCH /gondolas/{id}
     * Editar góndola.
     * Acceso: admin
     */
    public function update(Request $request, string $id)
    {
        $user = $request->user();

        if ($user->role !== 'admin') {
            return response()->json(['message' => 'Solo el administrador puede editar góndolas.'], 403);
        }

        $gondola = Gondola::where('empresa_id', $user->empresa_id)->findOrFail($id);

        $data = $request->validate([
            'nombre'      => ['sometimes', 'string', 'max:100'],
            'descripcion' => ['nullable', 'string', 'max:300'],
            'ubicacion'   => ['nullable', 'string', 'max:100'],
            'orden'       => ['nullable', 'integer'],
        ]);

        $gondola->update($data);

        return response()->json($gondola);
    }

    /**
     * DELETE /gondolas/{id}
     * Soft delete (activo = false). 409 si hay órdenes pendientes.
     * Acceso: admin
     */
    public function destroy(Request $request, string $id)
    {
        $user = $request->user();

        if ($user->role !== 'admin') {
            return response()->json(['message' => 'Solo el administrador puede eliminar góndolas.'], 403);
        }

        $gondola = Gondola::where('empresa_id', $user->empresa_id)->findOrFail($id);

        $pendientes = $gondola->ordenes()->whereIn('status', ['pendiente', 'en_proceso'])->count();
        if ($pendientes > 0) {
            return response()->json([
                'message' => 'No se puede desactivar la góndola porque tiene órdenes activas.',
            ], 409);
        }

        $gondola->update(['activo' => false]);

        return response()->json(['message' => 'Góndola desactivada correctamente.']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRODUCTOS DE GÓNDOLA
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * GET /gondolas/{id}/productos
     * Lista productos activos de la góndola.
     */
    public function productos(Request $request, string $id)
    {
        $gondola = Gondola::where('empresa_id', $request->user()->empresa_id)
            ->where('activo', true)
            ->findOrFail($id);

        $productos = $gondola->productos()->get();

        return response()->json($productos);
    }

    /**
     * POST /gondolas/{id}/productos
     * Agregar producto a la góndola.
     * Acceso: admin / supervisor
     */
    public function addProducto(Request $request, string $id)
    {
        $user = $request->user();

        if (!in_array($user->role, ['admin', 'supervisor'])) {
            return response()->json(['message' => 'Acceso denegado.'], 403);
        }

        $gondola = Gondola::where('empresa_id', $user->empresa_id)
            ->where('activo', true)
            ->findOrFail($id);

        $data = $request->validate([
            'nombre'      => ['required', 'string', 'max:150'],
            'clave'       => ['nullable', 'string', 'max:50'],
            'descripcion' => ['nullable', 'string', 'max:300'],
            'unidad'      => ['required', 'string', 'in:pz,kg,caja,media_caja'],
            'orden'       => ['nullable', 'integer'],
        ]);

        $producto = GondolaProducto::create([
            'empresa_id'  => $user->empresa_id,
            'gondola_id'  => $gondola->id,
            'nombre'      => $data['nombre'],
            'clave'       => $data['clave'] ?? null,
            'descripcion' => $data['descripcion'] ?? null,
            'unidad'      => $data['unidad'],
            'orden'       => $data['orden'] ?? 0,
            'activo'      => true,
        ]);

        return response()->json($producto, 201);
    }

    /**
     * PATCH /gondolas/{gId}/productos/{pId}
     * Editar producto.
     * Acceso: admin / supervisor
     */
    public function updateProducto(Request $request, string $gId, string $pId)
    {
        $user = $request->user();

        if (!in_array($user->role, ['admin', 'supervisor'])) {
            return response()->json(['message' => 'Acceso denegado.'], 403);
        }

        $producto = GondolaProducto::where('empresa_id', $user->empresa_id)
            ->where('gondola_id', $gId)
            ->findOrFail($pId);

        $data = $request->validate([
            'nombre'      => ['sometimes', 'string', 'max:150'],
            'clave'       => ['nullable', 'string', 'max:50'],
            'descripcion' => ['nullable', 'string', 'max:300'],
            'unidad'      => ['sometimes', 'string', 'in:pz,kg,caja,media_caja'],
            'orden'       => ['nullable', 'integer'],
        ]);

        $producto->update($data);

        return response()->json($producto);
    }

    /**
     * DELETE /gondolas/{gId}/productos/{pId}
     * Soft delete de producto (activo = false).
     * Acceso: admin / supervisor
     */
    public function removeProducto(Request $request, string $gId, string $pId)
    {
        $user = $request->user();

        if (!in_array($user->role, ['admin', 'supervisor'])) {
            return response()->json(['message' => 'Acceso denegado.'], 403);
        }

        $producto = GondolaProducto::where('empresa_id', $user->empresa_id)
            ->where('gondola_id', $gId)
            ->findOrFail($pId);

        $producto->update(['activo' => false]);

        return response()->json(['message' => 'Producto desactivado correctamente.']);
    }

    /**
     * POST /gondolas/{gId}/productos/{pId}/foto
     * Subir foto de referencia del producto a S3/Backblaze B2.
     * Acceso: admin / supervisor
     */
    public function uploadFoto(Request $request, string $gId, string $pId)
    {
        $user = $request->user();

        if (!in_array($user->role, ['admin', 'supervisor'])) {
            return response()->json(['message' => 'Acceso denegado.'], 403);
        }

        $producto = GondolaProducto::where('empresa_id', $user->empresa_id)
            ->where('gondola_id', $gId)
            ->findOrFail($pId);

        $request->validate([
            'file' => ['required', 'file', 'image', 'max:2048'],
        ]);

        $path = $request->file('file')->store(
            "kore/{$user->empresa_id}/gondola-productos/{$pId}",
            's3'
        );

        // PENDIENTE: Requiere bucket público en Backblaze B2.
        // Cuando se active el acceso público funcionará automáticamente sin cambios.
        $url = Storage::disk('s3')->url($path);

        $producto->update(['foto_url' => $url]);

        return response()->json(['foto_url' => $url]);
    }
}

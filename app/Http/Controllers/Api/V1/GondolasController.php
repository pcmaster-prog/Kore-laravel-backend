<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Models\Gondola;
use App\Models\GondolaProducto;
use App\Models\Product;
use Illuminate\Support\Facades\Gate;
use App\Http\Resources\GondolaResource;
use App\Http\Resources\GondolaOrdenResource;

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

        return GondolaResource::collection($gondolas);
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

        return (new GondolaResource($gondola))->response()->setStatusCode(201);
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

        return new GondolaResource($gondola);
    }

    /**
     * PATCH /gondolas/{id}
     * Editar góndola.
     * Acceso: admin
     */
    public function update(Request $request, string $id)
    {
        Gate::authorize('admin');

        $user = $request->user();
        $gondola = Gondola::where('empresa_id', $user->empresa_id)->findOrFail($id);

        $data = $request->validate([
            'nombre'      => ['sometimes', 'string', 'max:100'],
            'descripcion' => ['nullable', 'string', 'max:300'],
            'ubicacion'   => ['nullable', 'string', 'max:100'],
            'orden'       => ['nullable', 'integer'],
        ]);

        $gondola->update($data);

        return new GondolaResource($gondola);
    }

    /**
     * DELETE /gondolas/{id}
     * Soft delete (activo = false). 409 si hay órdenes pendientes.
     * Acceso: admin
     */
    public function destroy(Request $request, string $id)
    {
        Gate::authorize('admin');

        $user = $request->user();
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
     * Lista productos de la góndola.
     * Retrocompatibilidad: si no tiene product_id, devuelve datos legacy.
     */
    public function productos(Request $request, string $id)
    {
        $gondola = Gondola::where('empresa_id', $request->user()->empresa_id)
            ->where('activo', true)
            ->findOrFail($id);

        $productos = $gondola->productos()->with('product')->get()->map(function ($gp) {
            if ($gp->product_id && $gp->product) {
                return [
                    'id'           => $gp->product->id,
                    'location_id'  => $gp->id,
                    'sku'          => $gp->product->sku,
                    'name'         => $gp->product->name,
                    'description'  => $gp->product->description,
                    'default_unit' => $gp->product->default_unit,
                    'photo_url'    => $gp->product->photo_url,
                    'orden'        => $gp->orden,
                    'activo'       => $gp->activo,
                ];
            }

            // Legacy: datos almacenados directamente en gondola_productos
            return [
                'id'          => $gp->id,
                'location_id' => $gp->id,
                'empresa_id'  => $gp->empresa_id,
                'gondola_id'  => $gp->gondola_id,
                'clave'       => $gp->clave,
                'nombre'      => $gp->nombre,
                'descripcion' => $gp->descripcion,
                'unidad'      => $gp->unidad,
                'foto_url'    => $gp->foto_url,
                'orden'       => $gp->orden,
                'activo'      => $gp->activo,
                'created_at'  => $gp->created_at,
                'updated_at'  => $gp->updated_at,
            ];
        });

        return response()->json($productos);
    }

    /**
     * POST /gondolas/{id}/productos
     * Agregar producto maestro a la góndola.
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
            'product_id' => ['required', 'uuid', 'exists:products,id'],
            'orden'      => ['nullable', 'integer'],
        ]);

        $productoMaestro = Product::where('empresa_id', $user->empresa_id)
            ->where('is_active', true)
            ->findOrFail($data['product_id']);

        $existente = GondolaProducto::where('empresa_id', $user->empresa_id)
            ->where('gondola_id', $gondola->id)
            ->where('product_id', $productoMaestro->id)
            ->first();

        if ($existente) {
            if ($existente->activo) {
                return response()->json([
                    'message' => 'El producto ya está activo en esta góndola.',
                ], 409);
            }

            $existente->update(['activo' => true]);
            return response()->json($existente, 200);
        }

        $producto = GondolaProducto::create([
            'empresa_id'  => $user->empresa_id,
            'gondola_id'  => $gondola->id,
            'product_id'  => $productoMaestro->id,
            'clave'       => $productoMaestro->sku,
            'nombre'      => $productoMaestro->name,
            'descripcion' => $productoMaestro->description,
            'unidad'      => $productoMaestro->default_unit,
            'foto_url'    => $productoMaestro->photo_url,
            'orden'       => $data['orden'] ?? 0,
            'activo'      => true,
        ]);

        return response()->json($producto, 201);
    }

    /**
     * PATCH /gondolas/{gId}/productos/{pId}
     * Editar ubicación del producto en la góndola.
     * {pId} es el ID de gondola_productos.
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
            'orden'  => ['sometimes', 'nullable', 'integer'],
            'activo' => ['sometimes', 'boolean'],
        ]);

        $producto->update($data);

        return response()->json($producto);
    }

    /**
     * DELETE /gondolas/{gId}/productos/{pId}
     * Soft delete de la ubicación (activo = false).
     * {pId} es el ID de gondola_productos.
     * NO borra el producto maestro.
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

        return response()->json(['message' => 'Producto removido de la góndola correctamente.']);
    }

    /**
     * POST /gondolas/{gId}/productos/{pId}/foto
     * Subir foto al producto maestro.
     * {pId} es el product_id (producto maestro).
     * Acceso: admin / supervisor
     */
    public function uploadFoto(Request $request, string $gId, string $pId)
    {
        $user = $request->user();

        if (!in_array($user->role, ['admin', 'supervisor'])) {
            return response()->json(['message' => 'Acceso denegado.'], 403);
        }

        $producto = Product::where('empresa_id', $user->empresa_id)
            ->where('is_active', true)
            ->findOrFail($pId);

        $request->validate([
            'file' => ['required', 'file', 'image', 'max:2048'],
        ]);

        $path = $request->file('file')->store(
            "kore/{$user->empresa_id}/products/{$pId}/photo",
            's3'
        );

        $url = Storage::disk('s3')->url($path);

        $producto->update(['photo_url' => $url]);

        return response()->json(['foto_url' => $url]);
    }
}

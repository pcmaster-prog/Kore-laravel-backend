<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Gate;
use App\Models\Product;
use App\Models\Gondola;

class ProductsController extends Controller
{
    /**
     * GET /products
     * Lista productos maestros de la empresa.
     * Acceso: admin / supervisor
     */
    public function index(Request $request)
    {
        $user = $request->user();

        if (!in_array($user->role, ['admin', 'supervisor'])) {
            return response()->json(['message' => 'Acceso denegado.'], 403);
        }

        $query = Product::where('empresa_id', $user->empresa_id)
            ->withCount(['gondolaProductos as locations_count' => function ($q) {
                $q->where('activo', true);
            }]);

        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('sku', 'ilike', "%{$search}%");
            });
        }

        if ($request->filled('unit')) {
            $query->where('default_unit', $request->input('unit'));
        }

        $products = $query->orderBy('name')->paginate(20);

        return response()->json($products);
    }

    /**
     * POST /products
     * Crear producto maestro.
     * Acceso: admin / supervisor
     */
    public function store(Request $request)
    {
        $user = $request->user();

        if (!in_array($user->role, ['admin', 'supervisor'])) {
            return response()->json(['message' => 'Acceso denegado.'], 403);
        }

        $data = $request->validate([
            'sku'          => ['nullable', 'string', 'max:50'],
            'name'         => ['required', 'string', 'max:150'],
            'description'  => ['nullable', 'string'],
            'default_unit' => ['required', 'string', 'in:pz,kg,caja,media_caja'],
            'photo'        => ['nullable', 'file', 'image', 'max:2048'],
        ]);

        $product = Product::create([
            'empresa_id'   => $user->empresa_id,
            'sku'          => $data['sku'] ?? null,
            'name'         => $data['name'],
            'description'  => $data['description'] ?? null,
            'default_unit' => $data['default_unit'],
            'is_active'    => true,
        ]);

        if ($request->hasFile('photo')) {
            $path = $request->file('photo')->store(
                "kore/{$user->empresa_id}/products/{$product->id}/photo",
                's3'
            );
            $product->update(['photo_url' => Storage::disk('s3')->url($path)]);
        }

        return response()->json($product, 201);
    }

    /**
     * GET /products/{id}
     * Ver producto maestro con sus ubicaciones.
     */
    public function show(Request $request, string $id)
    {
        $user = $request->user();

        $product = Product::where('empresa_id', $user->empresa_id)
            ->with(['locations.gondola'])
            ->findOrFail($id);

        return response()->json($product);
    }

    /**
     * PATCH /products/{id}
     * Actualizar producto maestro.
     * Acceso: admin / supervisor
     */
    public function update(Request $request, string $id)
    {
        $user = $request->user();

        if (!in_array($user->role, ['admin', 'supervisor'])) {
            return response()->json(['message' => 'Acceso denegado.'], 403);
        }

        $product = Product::where('empresa_id', $user->empresa_id)->findOrFail($id);

        $data = $request->validate([
            'sku'          => ['sometimes', 'nullable', 'string', 'max:50'],
            'name'         => ['sometimes', 'string', 'max:150'],
            'description'  => ['sometimes', 'nullable', 'string'],
            'default_unit' => ['sometimes', 'string', 'in:pz,kg,caja,media_caja'],
            'photo'        => ['sometimes', 'nullable', 'file', 'image', 'max:2048'],
        ]);

        $updateData = array_diff_key($data, array_flip(['photo']));
        if (!empty($updateData)) {
            $product->update($updateData);
        }

        if ($request->hasFile('photo')) {
            $path = $request->file('photo')->store(
                "kore/{$user->empresa_id}/products/{$product->id}/photo",
                's3'
            );
            $product->update(['photo_url' => Storage::disk('s3')->url($path)]);
        }

        return response()->json($product);
    }

    /**
     * DELETE /products/{id}
     * Soft delete (is_active = false).
     * Solo si no hay órdenes activas pendientes en góndolas donde esté.
     * Acceso: admin
     */
    public function destroy(Request $request, string $id)
    {
        Gate::authorize('admin');

        $user = $request->user();
        $product = Product::where('empresa_id', $user->empresa_id)->findOrFail($id);

        $gondolaIds = $product->gondolaProductos()
            ->where('activo', true)
            ->pluck('gondola_id');

        $pendientes = \App\Models\GondolaOrden::whereIn('gondola_id', $gondolaIds)
            ->whereIn('status', ['pendiente', 'en_proceso'])
            ->count();

        if ($pendientes > 0) {
            return response()->json([
                'message' => 'No se puede desactivar el producto porque hay órdenes activas pendientes en góndolas donde está ubicado.',
            ], 409);
        }

        $product->update(['is_active' => false]);

        return response()->json(['message' => 'Producto desactivado correctamente.']);
    }

    /**
     * GET /products/{id}/locations
     * Lista de góndolas donde está este producto.
     */
    public function locations(Request $request, string $id)
    {
        $user = $request->user();

        $product = Product::where('empresa_id', $user->empresa_id)->findOrFail($id);

        $locations = $product->gondolaProductos()
            ->where('activo', true)
            ->with('gondola')
            ->orderBy('orden')
            ->get();

        return response()->json($locations);
    }
}

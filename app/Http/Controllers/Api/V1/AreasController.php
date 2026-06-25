<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Area;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AreasController extends Controller
{
    public function index(Request $request)
    {
        $u = $request->user();
        Gate::authorize('supervisor');

        $q = Area::where('empresa_id', $u->empresa_id);

        if ($request->filled('active')) {
            $q->where('is_active', $request->boolean('active'));
        }

        return response()->json($q->orderBy('sort_order')->get());
    }

    public function store(Request $request)
    {
        $u = $request->user();
        Gate::authorize('admin');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'icon' => ['nullable', 'string', 'max:60'],
            'sort_order' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $area = Area::create([
            'empresa_id' => $u->empresa_id,
            'name' => $data['name'],
            'icon' => $data['icon'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return response()->json(['item' => $area], 201);
    }

    public function show(Request $request, string $id)
    {
        $u = $request->user();
        Gate::authorize('supervisor');

        $area = Area::where('empresa_id', $u->empresa_id)->where('id', $id)->first();
        if (! $area) {
            return response()->json(['message' => 'No encontrado'], 404);
        }

        return response()->json(['item' => $area]);
    }

    public function update(Request $request, string $id)
    {
        $u = $request->user();
        Gate::authorize('admin');

        $area = Area::where('empresa_id', $u->empresa_id)->where('id', $id)->first();
        if (! $area) {
            return response()->json(['message' => 'No encontrado'], 404);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:60'],
            'sort_order' => ['sometimes', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $area->fill($data);
        $area->save();

        return response()->json(['item' => $area]);
    }

    public function destroy(Request $request, string $id)
    {
        $u = $request->user();
        Gate::authorize('admin');

        $area = Area::where('empresa_id', $u->empresa_id)->where('id', $id)->first();
        if (! $area) {
            return response()->json(['message' => 'No encontrado'], 404);
        }

        $area->delete();

        return response()->json(['message' => 'Eliminado']);
    }

    public function withSections(Request $request)
    {
        $u = $request->user();
        Gate::authorize('supervisor');

        $areas = Area::where('empresa_id', $u->empresa_id)
            ->where('is_active', true)
            ->with(['sections' => function ($q) {
                $q->where('is_active', true)->orderBy('sort_order');
            }])
            ->orderBy('sort_order')
            ->get();

        return response()->json(['data' => $areas]);
    }
}

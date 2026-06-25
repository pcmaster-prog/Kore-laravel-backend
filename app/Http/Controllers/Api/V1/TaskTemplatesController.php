<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TaskTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TaskTemplatesController extends Controller
{
    public function index(Request $request)
    {
        $u = $request->user();
        Gate::authorize('supervisor');

        $q = TaskTemplate::where('empresa_id', $u->empresa_id);

        if ($request->filled('active')) {
            $q->where('is_active', filter_var($request->string('active'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->has('show_in_dashboard')) {
            $q->where('show_in_dashboard', $request->boolean('show_in_dashboard'));
        }

        if ($request->filled('search')) {
            $s = $request->string('search');
            $q->where('title', 'ilike', "%{$s}%");
        }

        if ($request->filled('section')) {
            $q->where('section', $request->string('section'));
        }

        if ($request->filled('area_id')) {
            $q->where('area_id', $request->string('area_id'));
        }

        if ($request->filled('section_id')) {
            $q->where('section_id', $request->string('section_id'));
        }

        return response()->json($q->with(['area', 'section'])->orderBy('title')->paginate(20));
    }

    public function store(Request $request)
    {
        $u = $request->user();
        Gate::authorize('supervisor');

        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string'],
            'instructions' => ['nullable'], // JSON
            'estimated_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'priority' => ['nullable', Rule::in(['low', 'medium', 'high', 'urgent'])],
            'section' => ['nullable', 'string', 'max:120'],
            'department' => ['nullable', 'string', 'max:120'],
            'area_id' => ['nullable', 'uuid', 'exists:areas,id'],
            'section_id' => ['nullable', 'uuid', 'exists:sections,id'],
            'voice_note_enabled' => ['nullable', 'boolean'],
            'tags' => ['nullable'],
            'is_active' => ['nullable', 'boolean'],
            'show_in_dashboard' => ['nullable', 'boolean'],
        ]);

        $t = TaskTemplate::create([
            'empresa_id' => $u->empresa_id,
            'created_by' => $u->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'instructions' => $data['instructions'] ?? null,
            'estimated_minutes' => $data['estimated_minutes'] ?? null,
            'priority' => $data['priority'] ?? 'medium',
            'section' => $data['section'] ?? null,
            'department' => $data['department'] ?? null,
            'area_id' => $data['area_id'] ?? null,
            'section_id' => $data['section_id'] ?? null,
            'voice_note_enabled' => $data['voice_note_enabled'] ?? false,
            'tags' => $data['tags'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'show_in_dashboard' => $data['show_in_dashboard'] ?? false,
            'meta' => null,
        ]);

        return response()->json(['item' => $t], 201);
    }

    public function show(Request $request, string $id)
    {
        $u = $request->user();
        Gate::authorize('supervisor');

        $t = TaskTemplate::where('empresa_id', $u->empresa_id)->where('id', $id)->first();
        if (! $t) {
            return response()->json(['message' => 'No encontrado'], 404);
        }

        return response()->json(['item' => $t]);
    }

    public function update(Request $request, string $id)
    {
        $u = $request->user();
        $this->requireManager($u);

        $t = TaskTemplate::where('empresa_id', $u->empresa_id)->where('id', $id)->first();
        if (! $t) {
            return response()->json(['message' => 'No encontrado'], 404);
        }

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:180'],
            'description' => ['sometimes', 'nullable', 'string'],
            'instructions' => ['sometimes', 'nullable'],
            'estimated_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1440'],
            'priority' => ['sometimes', Rule::in(['low', 'medium', 'high', 'urgent'])],
            'section' => ['sometimes', 'nullable', 'string', 'max:120'],
            'department' => ['sometimes', 'nullable', 'string', 'max:120'],
            'area_id' => ['sometimes', 'nullable', 'uuid', 'exists:areas,id'],
            'section_id' => ['sometimes', 'nullable', 'uuid', 'exists:sections,id'],
            'voice_note_enabled' => ['sometimes', 'boolean'],
            'tags' => ['sometimes', 'nullable'],
            'is_active' => ['sometimes', 'boolean'],
            'show_in_dashboard' => ['sometimes', 'boolean'],
        ]);

        $t->fill($data);
        $t->save();

        return response()->json(['item' => $t]);
    }

    public function destroy(Request $request, string $id)
    {
        $u = $request->user();
        Gate::authorize('admin');

        $t = TaskTemplate::where('empresa_id', $u->empresa_id)->where('id', $id)->first();
        if (! $t) {
            return response()->json(['message' => 'No encontrado'], 404);
        }

        $t->delete();

        return response()->json(['message' => 'Eliminado']);
    }

    public function sections(Request $request)
    {
        $u = $request->user();
        Gate::authorize('supervisor');

        $rows = TaskTemplate::where('empresa_id', $u->empresa_id)
            ->whereNotNull('section')
            ->selectRaw('DISTINCT section, department')
            ->get();

        $data = $rows->map(fn ($r) => [
            'id' => Str::slug($r->section),
            'name' => $r->section,
            'department' => $r->department,
        ])->values();

        return response()->json(['data' => $data]);
    }
}

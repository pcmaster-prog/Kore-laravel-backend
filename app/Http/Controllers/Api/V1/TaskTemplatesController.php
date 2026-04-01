<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

use App\Models\TaskTemplate;

class TaskTemplatesController extends Controller
{
    private function requireManager($u)
    {
        if (!in_array($u->role, ['admin','supervisor'])) {
            abort(response()->json(['message'=>'No autorizado'], 403));
        }
    }

    public function index(Request $request)
    {
        $u = $request->user();
        $this->requireManager($u);

        $q = TaskTemplate::where('empresa_id',$u->empresa_id);

        if ($request->filled('active')) {
            $q->where('is_active', filter_var($request->string('active'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->has('show_in_dashboard')) {
            $q->where('show_in_dashboard', $request->boolean('show_in_dashboard'));
        }

        if ($request->filled('search')) {
            $s = $request->string('search');
            $q->where('title','ilike',"%{$s}%");
        }

        return response()->json($q->orderBy('title')->paginate(20));
    }

    public function store(Request $request)
    {
        $u = $request->user();
        $this->requireManager($u);

        $data = $request->validate([
            'title' => ['required','string','max:180'],
            'description' => ['nullable','string'],
            'instructions' => ['nullable'], // JSON
            'estimated_minutes' => ['nullable','integer','min:1','max:1440'],
            'priority' => ['nullable', Rule::in(['low','medium','high','urgent'])],
            'tags' => ['nullable'],
            'is_active' => ['nullable','boolean'],
            'show_in_dashboard' => ['nullable','boolean'],
        ]);

        $t = TaskTemplate::create([
            'empresa_id'=>$u->empresa_id,
            'created_by'=>$u->id,
            'title'=>$data['title'],
            'description'=>$data['description'] ?? null,
            'instructions'=>$data['instructions'] ?? null,
            'estimated_minutes'=>$data['estimated_minutes'] ?? null,
            'priority'=>$data['priority'] ?? 'medium',
            'tags'=>$data['tags'] ?? null,
            'is_active'=>$data['is_active'] ?? true,
            'show_in_dashboard'=>$data['show_in_dashboard'] ?? false,
            'meta'=>null,
        ]);

        return response()->json(['item'=>$t], 201);
    }

    public function show(Request $request, string $id)
    {
        $u = $request->user();
        $this->requireManager($u);

        $t = TaskTemplate::where('empresa_id',$u->empresa_id)->where('id',$id)->first();
        if (!$t) return response()->json(['message'=>'No encontrado'], 404);

        return response()->json(['item'=>$t]);
    }

    public function update(Request $request, string $id)
    {
        $u = $request->user();
        $this->requireManager($u);

        $t = TaskTemplate::where('empresa_id',$u->empresa_id)->where('id',$id)->first();
        if (!$t) return response()->json(['message'=>'No encontrado'], 404);

        $data = $request->validate([
            'title' => ['sometimes','string','max:180'],
            'description' => ['sometimes','nullable','string'],
            'instructions' => ['sometimes','nullable'],
            'estimated_minutes' => ['sometimes','nullable','integer','min:1','max:1440'],
            'priority' => ['sometimes', Rule::in(['low','medium','high','urgent'])],
            'tags' => ['sometimes','nullable'],
            'is_active' => ['sometimes','boolean'],
            'show_in_dashboard' => ['sometimes','boolean'],
        ]);

        $t->fill($data);
        $t->save();

        return response()->json(['item'=>$t]);
    }

    public function destroy(Request $request, string $id)
    {
        $u = $request->user();
        if ($u->role !== 'admin') {
            return response()->json(['message'=>'Solo admin puede eliminar'], 403);
        }

        $t = TaskTemplate::where('empresa_id',$u->empresa_id)->where('id',$id)->first();
        if (!$t) return response()->json(['message'=>'No encontrado'], 404);

        $t->delete();

        return response()->json(['message'=>'Eliminado']);
    }
}
